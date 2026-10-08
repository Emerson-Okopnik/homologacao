<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Actions\CreateUser;
use App\Domain\Users\Actions\ProvisionTenantRoles;
use App\Domain\Users\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TenantResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Administração de tenants pelo super admin. As contagens ignoram o TenantScope de
 * forma explícita, pois o contexto ativo é o tenant do próprio operador.
 */
final class TenantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $tenants = $this->withCounts(Tenant::query())
            ->when($validated['search'] ?? null, function (Builder $q, string $search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(slug) like ?', [$term]));
            })
            ->orderBy('name')
            ->get();

        return TenantResource::collection($tenants);
    }

    public function show(Tenant $tenant): TenantResource
    {
        return new TenantResource($this->withCounts(Tenant::query())->findOrFail($tenant->id));
    }

    public function store(
        Request $request,
        ProvisionTenantRoles $provision,
        CreateUser $createUser,
        TenantContext $context,
        AuditLogger $audit,
    ): JsonResponse {
        $request->merge([
            'slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name'))),
            'admin_email' => Str::lower(trim((string) $request->input('admin_email'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('tenants', 'slug')],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'string', Password::min(10)->letters()->numbers()->mixedCase()],
        ], [], [
            'name' => 'nome da empresa',
            'admin_name' => 'nome do administrador',
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha do administrador',
        ]);

        $tenant = DB::transaction(function () use ($data, $provision, $createUser, $context, $audit): Tenant {
            $tenant = Tenant::query()->create(['name' => $data['name'], 'slug' => $data['slug'], 'active' => true]);
            $provision->handle($tenant);

            $context->run($tenant, function () use ($data, $createUser, $audit, $tenant): void {
                $createUser->handle([
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'password' => $data['admin_password'],
                    'active' => true,
                ], [SystemRole::Administrator->value]);

                $audit->log('tenant.created', $tenant, null, ['name' => $tenant->name, 'slug' => $tenant->slug]);
            });

            return $tenant;
        });

        return (new TenantResource($this->withCounts(Tenant::query())->findOrFail($tenant->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Tenant $tenant, TenantContext $context, AuditLogger $audit): TenantResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (($data['active'] ?? true) === false && $request->user()?->tenant_id === $tenant->id) {
            throw ValidationException::withMessages(['active' => 'Você não pode desativar o tenant ao qual pertence.']);
        }

        $old = $tenant->only(array_keys($data));
        $tenant->update($data);

        if ($tenant->wasChanged()) {
            $context->run($tenant, fn () => $audit->log('tenant.updated', $tenant, $old, $tenant->only(array_keys($data))));
        }

        return new TenantResource($this->withCounts(Tenant::query())->findOrFail($tenant->id));
    }

    /**
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'users' => fn ($q) => $q->withoutGlobalScope(TenantScope::class),
            'roles' => fn ($q) => $q->withoutGlobalScope(TenantScope::class),
        ]);
    }
}
