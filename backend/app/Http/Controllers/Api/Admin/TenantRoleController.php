<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Perfis e permissões de um tenant. Toda operação roda dentro do contexto do tenant alvo,
 * preservando as garantias do TenantScope e do BelongsToTenant.
 */
final class TenantRoleController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function permissions(): JsonResponse
    {
        $items = collect(PermissionKey::cases())->map(fn (PermissionKey $p) => [
            'key' => $p->value,
            'label' => $p->label(),
            'group' => $p->group(),
            'group_label' => PermissionKey::groupLabel($p->group()),
        ])->values();

        return response()->json(['data' => $items]);
    }

    public function index(Tenant $tenant): AnonymousResourceCollection
    {
        $roles = $this->context->run($tenant, fn () => Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get());

        return RoleResource::collection($roles);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());

        $role = $this->context->run($tenant, fn () => DB::transaction(function () use ($data): Role {
            $role = Role::query()->create([
                'slug' => $this->uniqueSlug($data['name']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);
            $role->permissions()->sync($this->permissionIds($data['permissions'] ?? []));

            $this->audit->log('role.created', $role, null, [
                'name' => $role->name,
                'permissions' => array_values($data['permissions'] ?? []),
            ]);

            return $role->load('permissions')->loadCount('users');
        }));

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(Request $request, Tenant $tenant, string $role): RoleResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(array_column(PermissionKey::cases(), 'value'))],
        ], [], $this->attributes());

        $updated = $this->context->run($tenant, fn () => DB::transaction(function () use ($data, $role): Role {
            $model = Role::query()->with('permissions')->where('slug', $role)->firstOrFail();

            if ($model->slug === SystemRole::Administrator->value && array_key_exists('permissions', $data)) {
                throw ValidationException::withMessages([
                    'permissions' => 'O perfil Administrador sempre possui todas as permissões.',
                ]);
            }

            if ($model->is_system && array_key_exists('name', $data) && $data['name'] !== $model->name) {
                throw ValidationException::withMessages(['name' => 'O nome de um perfil padrão não pode ser alterado.']);
            }

            $oldPermissions = $model->permissions->pluck('key')->sort()->values()->all();
            $model->update(array_intersect_key($data, array_flip(['name', 'description'])));

            if (array_key_exists('permissions', $data)) {
                $model->permissions()->sync($this->permissionIds($data['permissions']));
                /** @var list<string> $permissions */
                $permissions = $data['permissions'];
                $newPermissions = collect($permissions)->sort()->values()->all();

                if ($oldPermissions !== $newPermissions) {
                    $this->audit->log('role.permissions_changed', $model,
                        ['permissions' => $oldPermissions], ['permissions' => $newPermissions]);
                }
            }

            return $model->load('permissions')->loadCount('users');
        }));

        return new RoleResource($updated);
    }

    public function destroy(Tenant $tenant, string $role): Response
    {
        $this->context->run($tenant, function () use ($role): void {
            $model = Role::query()->withCount('users')->where('slug', $role)->firstOrFail();

            if ($model->is_system) {
                throw ValidationException::withMessages(['role' => 'Perfis padrão não podem ser excluídos.']);
            }

            if ($model->users_count > 0) {
                throw ValidationException::withMessages(['role' => 'Remova os usuários deste perfil antes de excluí-lo.']);
            }

            $this->audit->log('role.deleted', $model, ['name' => $model->name, 'slug' => $model->slug], null);
            $model->delete();
        });

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(array_column(PermissionKey::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['name' => 'nome', 'description' => 'descrição', 'permissions' => 'permissões'];
    }

    /**
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function permissionIds(array $keys): array
    {
        return Permission::query()->whereIn('key', $keys)->pluck('id')->all();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'perfil';
        $slug = $base;
        $i = 2;

        while (Role::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}_{$i}";
            $i++;
        }

        return $slug;
    }
}
