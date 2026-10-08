<?php

use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;

it('cadastra equipamento com decimais e cria projeto com processo vinculado', function (): void {
    $tenant = tenantWithRoles();
    $user = userWithRole($tenant, SystemRole::Administrator);
    [$client, $unit] = app(TenantContext::class)->run($tenant, function (): array {
        $client = Client::create(['type' => 'PF', 'document' => '52998224725', 'name' => 'Cliente teste']);
        $distributor = Distributor::create(['code' => 'TEST', 'name' => 'Distribuidora teste']);
        $unit = ConsumerUnit::create([
            'client_id' => $client->id, 'distributor_id' => $distributor->id,
            'number' => '123', 'street' => 'Rua teste', 'city' => 'São Paulo', 'state' => 'SP',
        ]);

        return [$client, $unit];
    });
    $this->actingAs($user, 'web');

    $equipment = $this->postJson('/api/equipment', [
        'type' => 'module', 'manufacturer' => 'Fabricante teste', 'model' => 'Modelo teste',
        'power_w' => 550.5, 'energy_kwh' => null, 'efficiency' => 21.5, 'certification' => null, 'active' => true,
    ])->assertCreated()
        ->assertJsonPath('data.power_w', 550.5)
        ->assertJsonPath('data.efficiency', 21.5)
        ->json('data.id');

    $project = $this->postJson('/api/projects', [
        'client_id' => $client->uuid, 'consumer_unit_id' => $unit->uuid, 'technical_responsible_id' => null,
        'modality' => 'autoconsumo_local', 'installed_power_kwp' => 5.505, 'inverter_power_kw' => 5.25,
        'has_battery' => false, 'estimated_generation_kwh_month' => 720.5, 'notes' => null,
        'equipment' => [['id' => $equipment, 'quantity' => 10]],
    ])->assertCreated()
        ->assertJsonPath('data.installed_power_kwp', 5.505)
        ->assertJsonPath('data.generation_type', 'micro')
        ->assertJsonPath('data.equipment.0.id', $equipment)
        ->assertJsonPath('data.equipment.0.quantity', 10)
        ->assertJsonPath('data.process.status', 'rascunho')
        ->json('data');

    $this->getJson('/api/projects/'.$project['id'])->assertOk()->assertJsonPath('data.process.id', $project['process']['id']);
    $this->assertDatabaseCount('solar_projects', 1);
    $this->assertDatabaseCount('homologation_processes', 1);
    $this->assertDatabaseHas('process_status_histories', ['tenant_id' => $tenant->id, 'to_status' => 'rascunho']);
    $this->assertDatabaseHas('project_equipment', ['tenant_id' => $tenant->id, 'quantity' => 10]);

    $this->getJson('/api/processes/'.$project['process']['id'])->assertOk()->assertJsonPath('data.project.id', $project['id']);
    $this->postJson('/api/processes/'.$project['process']['id'].'/transitions', ['status' => 'em_preparacao'])
        ->assertOk()->assertJsonPath('data.status', 'em_preparacao');
    $this->assertDatabaseHas('process_status_histories', [
        'tenant_id' => $tenant->id, 'from_status' => 'rascunho', 'to_status' => 'em_preparacao', 'user_id' => $user->id,
    ]);
});
