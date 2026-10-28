<?php

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Projects\Models\ClientRequest;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;

it('pagina os cadastros técnicos sem repetir registros nem incluir outra empresa', function (string $endpoint, string $model): void {
    $tenant = tenantWithRoles();
    $user = userWithRole($tenant, SystemRole::Administrator);
    foreach ([$tenant, tenantWithRoles()] as $owner) {
        app(TenantContext::class)->run($owner, function () use ($model): void {
            for ($index = 1; $index <= 11; $index++) {
                $model::create($model === EquipmentItem::class
                    ? ['type' => 'module', 'manufacturer' => 'Teste', 'model' => sprintf('M%02d', $index)]
                    : ['name' => 'Mesmo nome', 'council' => 'CREA', 'registration' => (string) $index, 'state' => 'SC']);
            }
        });
    }
    $this->actingAs($user, 'web');

    $first = $this->getJson("/api/{$endpoint}?per_page=5")->assertOk()
        ->assertJsonPath('meta.total', 11)->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.per_page', 5)->assertJsonCount(5, 'data')->json('data');
    $second = $this->getJson("/api/{$endpoint}?per_page=5&page=2")->assertOk()
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.from', 6)->assertJsonCount(5, 'data')->json('data');
    expect(array_intersect(array_column($first, 'id'), array_column($second, 'id')))->toBe([]);
    $this->getJson("/api/{$endpoint}?per_page=10")->assertOk()
        ->assertJsonPath('meta.per_page', 10)->assertJsonCount(10, 'data');
    $this->getJson("/api/{$endpoint}?per_page=101")->assertUnprocessable()->assertJsonValidationErrors('per_page');
})->with([
    'equipamentos' => ['equipment', EquipmentItem::class],
    'responsáveis' => ['technical-responsibles', TechnicalResponsible::class],
]);

it('pagina as solicitações mantendo filtros, contagens e isolamento por empresa', function (): void {
    $tenant = tenantWithRoles();
    $user = userWithRole($tenant, SystemRole::Administrator);
    foreach ([$tenant, tenantWithRoles()] as $owner) {
        app(TenantContext::class)->run($owner, function (): void {
            $client = Client::create(['type' => 'PF', 'document' => '52998224725', 'name' => 'Titular da paginação']);
            $distributor = Distributor::firstOrCreate(['code' => 'PAG'], ['name' => 'Distribuidora']);
            $unit = ConsumerUnit::create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'number' => '123', 'street' => 'Rua teste', 'city' => 'Florianópolis', 'state' => 'SC']);
            for ($index = 1; $index <= 11; $index++) {
                ClientRequest::create(['client_id' => $client->id, 'consumer_unit_id' => $unit->id, 'code' => sprintf('SOL-%02d', $index), 'system' => []]);
            }
        });
    }
    $this->actingAs($user, 'web');
    $first = $this->getJson('/api/client-requests?per_page=5&status=SUBMITTED')->assertOk()
        ->assertJsonPath('meta.total', 11)->assertJsonPath('meta.last_page', 3)
        ->assertJsonCount(5, 'data')->assertJsonPath('counts.SUBMITTED', 11)->json('data');
    $second = $this->getJson('/api/client-requests?per_page=5&page=2&status=SUBMITTED')->assertOk()
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.from', 6)->assertJsonCount(5, 'data')->json('data');
    expect(array_intersect(array_column($first, 'id'), array_column($second, 'id')))->toBe([]);
    $this->getJson('/api/client-requests?search=SOL-01&per_page=5')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data');
    $this->getJson('/api/client-requests?status=CANCELLED')->assertOk()
        ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.from', null)->assertJsonCount(0, 'data');
    $this->getJson('/api/client-requests?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('respeita o tamanho da página de usuários da empresa e valida documentos', function (): void {
    $tenant = tenantWithRoles();
    $admin = userWithRole($tenant, SystemRole::Administrator, ['is_super_admin' => true]);
    for ($index = 1; $index <= 10; $index++) {
        userWithRole($tenant, SystemRole::ReadOnly);
    }
    $this->actingAs($admin, 'web');
    $this->getJson('/api/admin/tenants/'.$tenant->uuid.'/users?per_page=5&page=2')->assertOk()
        ->assertJsonPath('meta.total', 11)->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.current_page', 2)->assertJsonCount(5, 'data');
    $this->getJson('/api/documents?per_page=10')->assertOk()->assertJsonPath('meta.per_page', 10);
    $this->getJson('/api/documents?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});
