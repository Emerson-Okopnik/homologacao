<?php

use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Projects\Models\ClientRequest;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\User;

function accountLinkSession($test, User $user)
{
    $test->flushSession();
    app('auth')->forgetGuards();

    return $test->actingAs($user, 'web');
}

beforeEach(function (): void {
    $this->tenant = tenantWithRoles();
    $this->admin = userWithRole($this->tenant, SystemRole::Administrator);
    $this->staff = userWithRole($this->tenant, SystemRole::TechnicalResponsible);
    $this->records = app(TenantContext::class)->run($this->tenant, function (): array {
        $client = Client::create(['type' => 'PF', 'document' => '52998224725', 'name' => 'Titular teste']);
        $otherClient = Client::create(['type' => 'PF', 'document' => '11144477735', 'name' => 'Outro titular']);
        $distributor = Distributor::create(['code' => 'TEST', 'name' => 'Distribuidora']);
        $unit = ConsumerUnit::create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'number' => '123', 'street' => 'Rua teste', 'city' => 'Florianópolis', 'state' => 'SC']);
        $otherUnit = ConsumerUnit::create(['client_id' => $otherClient->id, 'distributor_id' => $distributor->id, 'number' => '456', 'street' => 'Rua teste', 'city' => 'Florianópolis', 'state' => 'SC']);
        $source = ClientRequest::create(['client_id' => $client->id, 'consumer_unit_id' => $unit->id, 'code' => 'SOL-001', 'system' => []]);
        $otherSource = ClientRequest::create(['client_id' => $otherClient->id, 'consumer_unit_id' => $otherUnit->id, 'code' => 'SOL-002', 'system' => []]);

        return compact('client', 'unit', 'source', 'otherSource');
    });
    $this->rtPayload = ['name' => 'Engenheiro', 'council' => 'CREA', 'registration' => '123456', 'state' => 'SC', 'email' => 'engineer@example.test', 'registration_status' => 'regular', 'active' => true];
    accountLinkSession($this, $this->admin);
});

it('salva o vínculo do RT, permite a atribuição e preserva a conta nas edições que omitem user_id', function (): void {
    $rt = $this->postJson('/api/technical-responsibles', [...$this->rtPayload, 'user_id' => $this->staff->uuid])
        ->assertCreated()->assertJsonPath('data.user.id', $this->staff->uuid)->json('data.id');
    $this->putJson('/api/technical-responsibles/'.$rt, [...$this->rtPayload, 'phone' => '48999999999'])
        ->assertOk()->assertJsonPath('data.user.id', $this->staff->uuid);
    $this->getJson('/api/technical-responsibles')->assertOk()->assertJsonPath('data.0.user.id', $this->staff->uuid);
    $this->postJson('/api/client-requests/'.$this->records['source']->uuid.'/assign', ['technical_responsible_id' => $rt])
        ->assertOk()->assertJsonPath('data.status.value', 'IN_REVIEW');
    accountLinkSession($this, $this->staff)->getJson('/api/client-requests?mine=1')->assertOk()->assertJsonCount(1, 'data');
    accountLinkSession($this, $this->admin);
    $this->putJson('/api/technical-responsibles/'.$rt, [...$this->rtPayload, 'user_id' => null])->assertOk()->assertJsonPath('data.user', null);
    $this->postJson('/api/client-requests/'.$this->records['source']->uuid.'/assign', ['technical_responsible_id' => $rt])
        ->assertUnprocessable()->assertJsonPath('code', 'responsible_without_user');
    $this->putJson('/api/technical-responsibles/'.$rt, [...$this->rtPayload, 'user_id' => $this->staff->uuid])->assertOk();
    app(TenantContext::class)->run($this->tenant, fn () => $this->staff->update(['active' => false]));
    $this->postJson('/api/client-requests/'.$this->records['source']->uuid.'/assign', ['technical_responsible_id' => $rt])
        ->assertUnprocessable()->assertJsonPath('code', 'responsible_user_unavailable');
});

it('oferece somente contas internas elegíveis e rejeita vínculos inválidos', function (string $kind): void {
    $target = match ($kind) {
        'portal' => userWithRole($this->tenant, SystemRole::Client),
        'inactive' => userWithRole($this->tenant, SystemRole::TechnicalResponsible, ['active' => false]),
        'readonly' => userWithRole($this->tenant, SystemRole::ReadOnly),
        'other_tenant' => userWithRole(tenantWithRoles(), SystemRole::TechnicalResponsible),
    };
    $this->getJson('/api/technical-responsibles/users')->assertOk()->assertJsonMissing(['id' => $target->uuid])->assertJsonFragment(['id' => $this->staff->uuid]);
    $this->postJson('/api/technical-responsibles', [...$this->rtPayload, 'user_id' => $target->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->assertDatabaseCount('technical_responsibles', 0);
})->with(['portal', 'inactive', 'readonly', 'other_tenant']);

it('vincula o usuário Cliente ao titular e permite somente portal.access e dados desse titular', function (): void {
    $client = $this->records['client'];
    $portal = $this->postJson('/api/users', ['name' => 'Cliente', 'email' => 'portal@example.test', 'password' => 'PortalTest123!', 'roles' => ['cliente'], 'client_id' => $client->uuid])
        ->assertCreated()->assertJsonPath('data.client.id', $client->uuid)->json('data.id');
    $this->getJson('/api/users')->assertOk()->assertJsonFragment(['client' => ['id' => $client->uuid, 'name' => $client->name]]);
    $this->patchJson('/api/users/'.$portal, ['name' => 'Cliente atualizado'])->assertOk()->assertJsonPath('data.client.id', $client->uuid);
    $user = app(TenantContext::class)->run($this->tenant, fn () => User::where('uuid', $portal)->firstOrFail());
    $this->postJson('/api/auth/logout')->assertNoContent();
    app('auth')->forgetGuards();
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'PortalTest123!'])->assertOk()
        ->assertJsonPath('data.permissions', ['portal.access'])->assertJsonPath('data.client.id', $client->uuid);
    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.permissions', ['portal.access'])->assertJsonPath('data.client.id', $client->uuid);
    $this->getJson('/api/portal/summary')->assertOk()->assertJsonPath('data.requests_total', 1);
    $this->getJson('/api/portal/units')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/portal/requests')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/portal/requests/'.$this->records['otherSource']->uuid)->assertNotFound();
    $this->getJson('/api/projects')->assertForbidden();
    $this->getJson('/api/dashboard')->assertForbidden();
    $this->getJson('/api/technical-responsibles/users')->assertForbidden();
});

it('corrige uma conta Cliente antiga sem vínculo e mantém a permissão exclusiva do portal', function (): void {
    $user = userWithRole($this->tenant, SystemRole::Client);
    accountLinkSession($this, $user)->getJson('/api/portal/summary')
        ->assertForbidden()->assertJsonPath('code', 'portal_client_not_linked');
    accountLinkSession($this, $this->admin)->patchJson('/api/users/'.$user->uuid, ['client_id' => $this->records['client']->uuid])
        ->assertOk()->assertJsonPath('data.client.id', $this->records['client']->uuid);
    accountLinkSession($this, $user->fresh())->getJson('/api/portal/requests')->assertOk();
});

it('rejeita acesso Cliente sem titular, misturado com equipe ou vinculado a outro tenant', function (string $kind): void {
    $client = $kind === 'foreign_client'
        ? app(TenantContext::class)->run(tenantWithRoles(), fn () => Client::create(['type' => 'PF', 'document' => '52998224725', 'name' => 'Titular externo']))
        : $this->records['client'];
    $payload = ['name' => 'Cliente', 'email' => 'invalid@example.test', 'password' => 'PortalTest123!', 'roles' => ['cliente'], 'client_id' => $client->uuid];
    if ($kind === 'missing_client') {
        unset($payload['client_id']);
    }
    if ($kind === 'mixed_roles') {
        $payload['roles'][] = 'responsavel_tecnico';
    }
    $count = User::withoutGlobalScopes()->count();
    $this->postJson('/api/users', $payload)->assertUnprocessable()->assertJsonValidationErrors($kind === 'mixed_roles' ? 'roles' : 'client_id');
    $this->assertDatabaseCount('users', $count);
})->with(['missing_client', 'mixed_roles', 'foreign_client']);

it('cria o acesso pela página do titular e permite o vínculo pelo super admin no tenant correto', function (): void {
    $client = $this->records['client'];
    $this->postJson('/api/clients/'.$client->uuid.'/portal-users', ['name' => 'Acesso titular', 'email' => 'holder@example.test', 'password' => 'PortalTest123!'])
        ->assertCreated();
    $portal = app(TenantContext::class)->run($this->tenant, fn () => User::where('email', 'holder@example.test')->firstOrFail());
    expect($portal->getAttribute('client_id'))->toBe($client->id);
    $this->admin->forceFill(['is_super_admin' => true])->save();
    $otherTenant = tenantWithRoles();
    $otherClient = app(TenantContext::class)->run($otherTenant, fn () => Client::create(['type' => 'PF', 'document' => '52998224725', 'name' => 'Titular externo']));
    $base = '/api/admin/tenants/'.$otherTenant->uuid;
    $this->getJson($base.'/clients')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otherClient->uuid);
    $user = $this->postJson($base.'/users', ['name' => 'Portal externo', 'email' => 'external@example.test', 'password' => 'PortalTest123!', 'roles' => ['cliente'], 'client_id' => $otherClient->uuid])
        ->assertCreated()->assertJsonPath('data.client.id', $otherClient->uuid)->json('data.id');
    $this->patchJson($base.'/users/'.$user, ['client_id' => $client->uuid])->assertUnprocessable()->assertJsonValidationErrors('client_id');
});
