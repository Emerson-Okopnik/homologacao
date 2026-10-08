<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Actions\CreateUser;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class TenantUserController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $users = $this->context->run($tenant, fn () => User::query()
            ->with('roles')
            ->when($validated['search'] ?? null, function ($q, string $search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term]));
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString());

        return UserResource::collection($users);
    }

    public function store(Request $request, Tenant $tenant, CreateUser $createUser): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()->mixedCase()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'slug')->where('tenant_id', $tenant->id)],
        ], [], ['name' => 'nome', 'email' => 'e-mail', 'password' => 'senha', 'roles' => 'perfis']);

        $user = $this->context->run($tenant, fn () => $createUser->handle(
            ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'active' => true],
            $data['roles'],
        ));

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(Request $request, Tenant $tenant, string $user): UserResource
    {
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'is_super_admin' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'slug')->where('tenant_id', $tenant->id)],
        ], [], ['active' => 'status', 'roles' => 'perfis', 'is_super_admin' => 'super admin']);

        $actor = $request->user();

        $updated = $this->context->run($tenant, fn () => DB::transaction(function () use ($data, $user, $actor): User {
            $model = User::query()->with('roles')->where('uuid', $user)->firstOrFail();
            $isSelf = $actor?->is($model) ?? false;

            if ($isSelf && ($data['active'] ?? true) === false) {
                throw ValidationException::withMessages(['active' => 'Você não pode desativar o próprio usuário.']);
            }

            if ($isSelf && ($data['is_super_admin'] ?? true) === false) {
                throw ValidationException::withMessages(['is_super_admin' => 'Você não pode remover o próprio acesso de super admin.']);
            }

            if (array_key_exists('active', $data)) {
                $model->active = $data['active'];
            }

            if (array_key_exists('is_super_admin', $data) && $model->isSuperAdmin() !== $data['is_super_admin']) {
                $model->forceFill(['is_super_admin' => $data['is_super_admin']]);
                $this->audit->log('user.super_admin_changed', $model,
                    ['is_super_admin' => ! $data['is_super_admin']], ['is_super_admin' => $data['is_super_admin']]);
            }

            $model->save();

            if (array_key_exists('roles', $data)) {
                $old = $model->roles->pluck('slug')->sort()->values()->all();
                /** @var list<string> $roles */
                $roles = $data['roles'];
                $new = collect($roles)->sort()->values()->all();

                if ($old !== $new) {
                    $model->roles()->sync(Role::query()->whereIn('slug', $new)->pluck('id'));
                    $this->audit->log('user.roles_changed', $model, ['roles' => $old], ['roles' => $new]);
                }
            }

            return $model->load('roles');
        }));

        return new UserResource($updated);
    }
}
