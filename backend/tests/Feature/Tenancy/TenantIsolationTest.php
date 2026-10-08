<?php

use App\Domain\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;

beforeEach(function (): void {
    $this->tenantA = tenantWithRoles(['name' => 'Tenant A']);
    $this->tenantB = tenantWithRoles(['name' => 'Tenant B']);
    $this->adminA = userWithRole($this->tenantA, SystemRole::Administrator);
    $this->userB = userWithRole($this->tenantB, SystemRole::Homologator);
});

it('é fail-closed: sem tenant no contexto nenhuma linha é retornada', function (): void {
    app(TenantContext::class)->clear();

    expect(User::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0);
});

it('filtra as queries pelo tenant do contexto', function (): void {
    $emails = app(TenantContext::class)->run($this->tenantA, fn () => User::query()->pluck('email'));

    expect($emails)->toContain($this->adminA->email)
        ->not->toContain($this->userB->email);
});

it('impede criar registro sem tenant resolvido', function (): void {
    app(TenantContext::class)->clear();

    User::query()->create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'Senha12345']);
})->throws(TenantNotResolvedException::class);

it('impede criar registro em tenant diferente do contexto', function (): void {
    app(TenantContext::class)->run($this->tenantA, fn () => User::factory()->forTenant($this->tenantB)->create());
})->throws(LogicException::class);

it('impede alterar o tenant de um registro existente', function (): void {
    app(TenantContext::class)->run($this->tenantA, function (): void {
        $user = User::query()->findOrFail($this->adminA->id);
        $user->tenant_id = $this->tenantB->id;
        $user->save();
    });
})->throws(LogicException::class);

it('retorna 404 ao acessar por UUID um usuário de outro tenant', function (): void {
    $this->actingAs($this->adminA, 'web')
        ->getJson("/api/users/{$this->userB->uuid}")
        ->assertNotFound();
});

it('não permite atualizar usuário de outro tenant via URL manual', function (): void {
    $this->actingAs($this->adminA, 'web')
        ->patchJson("/api/users/{$this->userB->uuid}", ['name' => 'Invadido'])
        ->assertNotFound();

    expect($this->userB->fresh()->name)->not->toBe('Invadido');
});

it('lista apenas usuários do próprio tenant', function (): void {
    $this->actingAs($this->adminA, 'web')
        ->getJson('/api/users')
        ->assertOk()
        ->assertJsonFragment(['email' => $this->adminA->email])
        ->assertJsonMissing(['email' => $this->userB->email]);
});

it('ignora roles de outro tenant ao criar usuário', function (): void {
    $this->actingAs($this->adminA, 'web')
        ->postJson('/api/users', [
            'name' => 'Novo Usuário',
            'email' => 'novo@example.com',
            'password' => 'Senha@12345',
            'password_confirmation' => 'Senha@12345',
            'roles' => [SystemRole::Homologator->value],
        ])
        ->assertCreated();

    $created = app(TenantContext::class)->run(
        $this->tenantA,
        fn () => User::query()->where('email', 'novo@example.com')->firstOrFail(),
    );

    expect($created->tenant_id)->toBe($this->tenantA->id)
        ->and($created->roles->pluck('tenant_id')->unique()->all())->toBe([$this->tenantA->id]);
});

it('desloga e bloqueia usuário quando o tenant é desativado', function (): void {
    $this->tenantA->forceFill(['active' => false])->save();

    $this->actingAs($this->adminA, 'web')
        ->getJson('/api/auth/me')
        ->assertForbidden();
});
