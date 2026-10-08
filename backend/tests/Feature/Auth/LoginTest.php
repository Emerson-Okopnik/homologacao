<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Users\Enums\SystemRole;

beforeEach(function (): void {
    $this->tenant = tenantWithRoles();
    $this->user = userWithRole($this->tenant, SystemRole::Homologator, [
        'email' => 'hom@example.com',
        'password' => 'Senha12345',
    ]);
});

it('autentica com credenciais válidas e retorna o usuário com permissões', function (): void {
    $this->postJson('/api/auth/login', ['email' => 'hom@example.com', 'password' => 'Senha12345'])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'hom@example.com')
        ->assertJsonPath('data.tenant.slug', $this->tenant->slug)
        ->assertJsonFragment(['homologations.manage']);

    $this->assertAuthenticatedAs($this->user, 'web');
});

it('retorna mensagem genérica para senha inválida e audita a falha', function (): void {
    $this->postJson('/api/auth/login', ['email' => 'hom@example.com', 'password' => 'errada'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'E-mail ou senha inválidos.');

    $this->assertGuest('web');

    expect(AuditLog::query()->withoutGlobalScope(TenantScope::class)
        ->where('event', 'auth.login_failed')->exists())->toBeTrue();
});

it('usa a mesma mensagem para e-mail inexistente (sem enumeração de usuários)', function (): void {
    $this->postJson('/api/auth/login', ['email' => 'naoexiste@example.com', 'password' => 'Senha12345'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'E-mail ou senha inválidos.');
});

it('bloqueia usuário inativo', function (): void {
    $this->user->forceFill(['active' => false])->saveQuietly();

    $this->postJson('/api/auth/login', ['email' => 'hom@example.com', 'password' => 'Senha12345'])
        ->assertUnprocessable();

    $this->assertGuest('web');
});

it('bloqueia login quando o tenant está inativo', function (): void {
    $this->tenant->forceFill(['active' => false])->save();

    $this->postJson('/api/auth/login', ['email' => 'hom@example.com', 'password' => 'Senha12345'])
        ->assertUnprocessable();

    $this->assertGuest('web');
});

it('aplica rate limit após tentativas falhas consecutivas', function (): void {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/auth/login', ['email' => 'hom@example.com', 'password' => 'errada']);
    }

    $this->postJson('/api/auth/login', ['email' => 'hom@example.com', 'password' => 'Senha12345'])
        ->assertStatus(429);
});

it('exige autenticação em /me', function (): void {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

it('encerra a sessão no logout e registra auditoria', function (): void {
    $this->actingAs($this->user, 'web')
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    expect(AuditLog::query()->withoutGlobalScope(TenantScope::class)
        ->where('event', 'auth.logout')->where('user_id', $this->user->id)->exists())->toBeTrue();
});
