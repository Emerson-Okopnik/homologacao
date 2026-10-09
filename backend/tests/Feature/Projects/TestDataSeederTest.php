<?php

use App\Domain\Projects\Models\SolarProject;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;
use Database\Seeders\TestDataSeeder;

it('carrega cenários visíveis na API e permite repetir a carga sem duplicar ou sobrescrever', function () {
    $tenant = tenantWithRoles(['slug' => 'demo-solar']);
    $user = userWithRole($tenant, SystemRole::Administrator);
    $other = tenantWithRoles(['slug' => 'outra-empresa']);
    $this->seed(TestDataSeeder::class);
    $this->actingAs($user, 'web');
    $this->getJson('/api/clients?status=active&per_page=100')->assertOk()->assertJsonCount(8, 'data');
    $this->getJson('/api/technical-responsibles?active=1')->assertOk()->assertJsonCount(6, 'data');
    $this->getJson('/api/equipment?active=1')->assertOk()->assertJsonCount(14, 'data');
    $this->getJson('/api/projects?per_page=100')->assertOk()->assertJsonCount(12, 'data');
    app(TenantContext::class)->run($tenant, fn () => SolarProject::where('code', 'TST-PROJ-001')->firstOrFail()->update(['name' => 'Nome alterado pelo usuário']));
    $this->seed(TestDataSeeder::class);
    $this->assertDatabaseCount('clients', 8);
    $this->assertDatabaseCount('consumer_units', 16);
    $this->assertDatabaseCount('technical_responsibles', 6);
    $this->assertDatabaseCount('equipment_catalog', 14);
    $this->assertDatabaseCount('solar_projects', 12);
    $this->assertDatabaseCount('homologation_processes', 12);
    $this->assertDatabaseHas('solar_projects', ['code' => 'TST-PROJ-001', 'name' => 'Nome alterado pelo usuário']);
    app(TenantContext::class)->run($other, fn () => expect(SolarProject::count())->toBe(0));
});
