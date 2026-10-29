<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Exceptions\AuditTenantMissingException;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = tenantWithRoles();
    $this->admin = userWithRole($this->tenant, SystemRole::Administrator);
});

it('registra alterações de modelo com valores antigos e novos', function (): void {
    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/users/{$this->admin->uuid}", ['name' => 'Nome Alterado'])
        ->assertOk();

    $entry = AuditLog::query()->withoutGlobalScope(TenantScope::class)
        ->where('event', 'user.updated')->latest('id')->firstOrFail();

    expect($entry->tenant_id)->toBe($this->tenant->id)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->new_values['name'] ?? null)->toBe('Nome Alterado');
});

it('nunca grava senha ou segredos no audit log', function (): void {
    app(TenantContext::class)->run($this->tenant, fn () => app(AuditLogger::class)->log(
        'test.secret',
        new: ['password' => 'Senha12345', 'nested' => ['api_key' => 'abc', 'client_secret' => 'xyz'], 'ok' => 'visivel'],
        actor: $this->admin,
    ));

    $entry = AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('event', 'test.secret')->firstOrFail();

    expect($entry->new_values['password'])->toBe('[REDACTED]')
        ->and($entry->new_values['nested']['api_key'])->toBe('[REDACTED]')
        ->and($entry->new_values['nested']['client_secret'])->toBe('[REDACTED]')
        ->and($entry->new_values['ok'])->toBe('visivel');
});

it('falha ao auditar sem tenant (sem registros órfãos)', function (): void {
    app(TenantContext::class)->clear();

    app(AuditLogger::class)->log('test.orphan');
})->throws(AuditTenantMissingException::class);

it('é append-only no banco: UPDATE é rejeitado', function (): void {
    $entry = app(TenantContext::class)->run($this->tenant, fn () => app(AuditLogger::class)->log('test.immutable', actor: $this->admin));

    DB::table('audit_logs')->where('id', $entry->id)->update(['event' => 'tampered']);
})->throws(QueryException::class);

it('é append-only no banco: DELETE é rejeitado', function (): void {
    $entry = app(TenantContext::class)->run($this->tenant, fn () => app(AuditLogger::class)->log('test.immutable', actor: $this->admin));

    DB::table('audit_logs')->where('id', $entry->id)->delete();
})->throws(QueryException::class);

it('propaga o correlation id da request', function (): void {
    $this->actingAs($this->admin, 'web')
        ->withHeader('X-Correlation-Id', '0a1b2c3d-0000-4000-8000-000000000001')
        ->patchJson("/api/users/{$this->admin->uuid}", ['name' => 'Com Correlação'])
        ->assertOk()
        ->assertHeader('X-Correlation-Id', '0a1b2c3d-0000-4000-8000-000000000001');

    expect(AuditLog::query()->withoutGlobalScope(TenantScope::class)
        ->where('correlation_id', '0a1b2c3d-0000-4000-8000-000000000001')->exists())->toBeTrue();
});

it('a listagem de auditoria só mostra eventos do próprio tenant', function (): void {
    $other = tenantWithRoles();
    $otherUser = userWithRole($other, SystemRole::Administrator);
    app(TenantContext::class)->run($other, fn () => app(AuditLogger::class)->log('test.other_tenant', actor: $otherUser));

    $this->actingAs($this->admin, 'web')
        ->getJson('/api/audit-logs')
        ->assertOk()
        ->assertJsonMissing(['event' => 'test.other_tenant']);
});

it('distingue o evento exato do prefixo e permite consultar somente a data final', function (): void {
    app(TenantContext::class)->run($this->tenant, function (): void {
        foreach (['auth.login', 'auth.login_failed', 'auth.logout'] as $event) {
            AuditLog::create(['event' => $event, 'user_id' => $this->admin->id, 'created_at' => '2026-10-13 23:59:59']);
        }
        AuditLog::create(['event' => 'auth.login', 'user_id' => $this->admin->id, 'created_at' => '2026-10-14 00:00:00']);
    });
    $this->actingAs($this->admin, 'web');
    $this->getJson('/api/audit-logs?event=auth.login&event_match=exact&from=2026-10-13&to=2026-10-13')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event', 'auth.login');
    $this->getJson('/api/audit-logs?event=auth.&event_match=prefix&to=2026-10-13')
        ->assertOk()->assertJsonPath('meta.total', 3);
    $this->getJson('/api/audit-logs?from=2026-10-20&to=2026-10-10')
        ->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson('/api/audit-logs?event_match=invalid')
        ->assertUnprocessable()->assertJsonValidationErrors('event_match');
});
