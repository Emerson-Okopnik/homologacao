<?php

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Actions\ProvisionTenantRoles;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Cria um tenant com os perfis padrão provisionados.
 */
function tenantWithRoles(array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create($attributes);
    app(ProvisionTenantRoles::class)->handle($tenant);

    return $tenant;
}

/**
 * Cria um usuário no tenant informado com o perfil padrão indicado.
 */
function userWithRole(Tenant $tenant, SystemRole $role, array $attributes = []): User
{
    return app(TenantContext::class)->run($tenant, function () use ($tenant, $role, $attributes): User {
        $user = User::factory()->forTenant($tenant)->create($attributes);
        $user->roles()->sync(Role::query()->where('slug', $role->value)->pluck('id'));

        return $user->fresh();
    });
}
