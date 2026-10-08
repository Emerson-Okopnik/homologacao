<?php

namespace App\Domain\Users\Actions;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\Role;

/**
 * Sincroniza o catálogo de permissões e cria/atualiza os perfis padrão de um tenant.
 * Idempotente: pode rodar em todo deploy.
 */
final class ProvisionTenantRoles
{
    public function __construct(private readonly TenantContext $context) {}

    public static function syncPermissionCatalog(): void
    {
        foreach (PermissionKey::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['key' => $permission->value],
                ['label' => $permission->label(), 'group' => $permission->group()],
            );
        }
    }

    public function handle(Tenant $tenant): void
    {
        self::syncPermissionCatalog();
        $permissionIds = Permission::query()->pluck('id', 'key');

        $this->context->run($tenant, function () use ($permissionIds): void {
            foreach (SystemRole::cases() as $systemRole) {
                $role = Role::query()->updateOrCreate(
                    ['slug' => $systemRole->value],
                    ['name' => $systemRole->label(), 'is_system' => true],
                );

                $role->permissions()->sync(
                    collect($systemRole->defaultPermissions())
                        ->map(fn (PermissionKey $p) => $permissionIds[$p->value])
                        ->all(),
                );
            }
        });
    }
}
