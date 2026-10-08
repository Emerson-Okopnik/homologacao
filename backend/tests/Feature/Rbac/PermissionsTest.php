<?php

use App\Domain\Users\Actions\ProvisionTenantRoles;
use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\Role;
use App\Domain\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->tenant = tenantWithRoles();
});

it('provisiona os cinco perfis padrão de forma idempotente', function (): void {
    app(ProvisionTenantRoles::class)->handle($this->tenant);

    $slugs = app(TenantContext::class)->run($this->tenant, fn () => Role::query()->pluck('slug')->sort()->values()->all());

    expect($slugs)->toBe(collect(SystemRole::cases())->pluck('value')->sort()->values()->all());
});

it('concede ao administrador todas as permissões', function (): void {
    $admin = userWithRole($this->tenant, SystemRole::Administrator);

    foreach (PermissionKey::cases() as $permission) {
        expect($admin->hasPermission($permission))->toBeTrue();
    }
});

it('perfil consulta é somente leitura', function (): void {
    $user = userWithRole($this->tenant, SystemRole::ReadOnly);

    expect($user->hasPermission(PermissionKey::ProjectsView))->toBeTrue()
        ->and($user->hasPermission(PermissionKey::ProjectsManage))->toBeFalse()
        ->and($user->hasPermission(PermissionKey::UsersManage))->toBeFalse();
});

it('nega listar usuários sem users.view', function (SystemRole $role): void {
    $this->actingAs(userWithRole($this->tenant, $role), 'web')
        ->getJson('/api/users')
        ->assertForbidden();
})->with([SystemRole::Homologator, SystemRole::TechnicalResponsible, SystemRole::ReadOnly]);

it('gestor visualiza usuários mas não cria', function (): void {
    $manager = userWithRole($this->tenant, SystemRole::Manager);

    $this->actingAs($manager, 'web')->getJson('/api/users')->assertOk();

    $this->actingAs($manager, 'web')
        ->postJson('/api/users', [
            'name' => 'Teste',
            'email' => 't@example.com',
            'password' => 'Senha@12345',
            'password_confirmation' => 'Senha@12345',
            'roles' => [SystemRole::ReadOnly->value],
        ])
        ->assertForbidden();
});

it('somente quem tem audit.view acessa a auditoria', function (): void {
    $this->actingAs(userWithRole($this->tenant, SystemRole::Homologator), 'web')
        ->getJson('/api/audit-logs')->assertForbidden();

    $this->actingAs(userWithRole($this->tenant, SystemRole::Manager), 'web')
        ->getJson('/api/audit-logs')->assertOk();
});

it('administrador cria usuário com senha forte', function (): void {
    $this->actingAs(userWithRole($this->tenant, SystemRole::Administrator), 'web')
        ->postJson('/api/users', [
            'name' => 'Fraco',
            'email' => 'fraco@example.com',
            'password' => '123',
            'password_confirmation' => '123',
            'roles' => [SystemRole::ReadOnly->value],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('administrador não pode desativar a si mesmo', function (): void {
    $admin = userWithRole($this->tenant, SystemRole::Administrator);

    $this->actingAs($admin, 'web')
        ->patchJson("/api/users/{$admin->uuid}", ['active' => false])
        ->assertUnprocessable();

    expect($admin->fresh()->active)->toBeTrue();
});
