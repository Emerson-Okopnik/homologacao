<?php

use App\Domain\Distributors\Models\Distributor;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;

beforeEach(function (): void {
    $this->tenant = tenantWithRoles();
    $this->admin = userWithRole($this->tenant, SystemRole::Administrator);
    $this->actingAs($this->admin, 'web');
});

it('cadastra distribuidora com identificação e valores padrão', function (): void {
    $this->postJson('/api/distributors', ['code' => 'teste_sp', 'name' => 'Distribuidora teste', 'state' => 'sp'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'TESTE_SP')
        ->assertJsonPath('data.state', 'SP')
        ->assertJsonPath('data.integration_mode', 'assisted')
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.has_credential', false);

    $this->assertDatabaseHas('distributors', ['tenant_id' => $this->tenant->id, 'code' => 'TESTE_SP']);
    $this->getJson('/api/distributors')->assertOk()->assertJsonFragment(['name' => 'Distribuidora teste']);
});

it('permite cadastrar sem UF e informa os campos obrigatórios', function (): void {
    $this->postJson('/api/distributors', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name']);
    $this->postJson('/api/distributors', ['code' => 'TESTE', 'name' => 'Distribuidora teste'])
        ->assertCreated()->assertJsonPath('data.state', null);
});

it('impede códigos duplicados no mesmo tenant independentemente de maiúsculas', function (): void {
    $this->postJson('/api/distributors', ['code' => 'TESTE', 'name' => 'Distribuidora teste'])->assertCreated();
    $this->postJson('/api/distributors', ['code' => 'teste', 'name' => 'Outra distribuidora'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->assertDatabaseCount('distributors', 1);
});

it('permite o mesmo código em tenants distintos e mantém os registros isolados', function (): void {
    $otherTenant = tenantWithRoles();
    $other = app(TenantContext::class)->run($otherTenant, fn () => Distributor::create(['code' => 'TESTE', 'name' => 'Distribuidora de outro tenant']));

    $this->postJson('/api/distributors', ['code' => 'TESTE', 'name' => 'Distribuidora local', 'tenant_id' => $otherTenant->id])->assertCreated();
    $this->assertDatabaseHas('distributors', ['tenant_id' => $this->tenant->id, 'name' => 'Distribuidora local']);
    $this->getJson('/api/distributors')->assertOk()->assertJsonMissing(['name' => $other->name]);
    $this->putJson('/api/distributors/'.$other->uuid, ['integration_mode' => 'assisted'])->assertNotFound();
});

it('nega o cadastro sem permissão de configurar integrações', function (): void {
    $this->actingAs(userWithRole($this->tenant, SystemRole::ReadOnly), 'web')
        ->postJson('/api/distributors', ['code' => 'TESTE', 'name' => 'Distribuidora teste'])->assertForbidden();
    $this->assertDatabaseCount('distributors', 0);
});

it('preserva a configuração de credenciais ao atualizar uma distribuidora', function (): void {
    $id = $this->postJson('/api/distributors', [
        'code' => 'TESTE', 'name' => 'Distribuidora teste', 'secret_ref' => 'TESTE_API_TOKEN',
        'integration_mode' => 'api', 'portal_url' => 'https://example.com/portal',
    ])->assertCreated()->assertJsonPath('data.has_credential', true)->assertJsonMissingPath('data.secret_ref')->json('data.id');

    $this->putJson('/api/distributors/'.$id, ['integration_mode' => 'assisted', 'portal_url' => null, 'active' => false])
        ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.has_credential', true);
    $this->assertDatabaseHas('distributors', ['uuid' => $id, 'secret_ref' => 'TESTE_API_TOKEN']);
});
