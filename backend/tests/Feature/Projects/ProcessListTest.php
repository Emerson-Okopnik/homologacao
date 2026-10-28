<?php

use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Homologations\Enums\DeadlineType;
use App\Domain\Homologations\Enums\WorkflowStage;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;

function processListRecords(Tenant $tenant): void
{
    app(TenantContext::class)->run($tenant, function (): void {
        $client = Client::create(['type' => 'PF', 'document' => '52998224725', 'name' => 'Cliente da listagem']);
        $distributor = Distributor::create(['code' => 'LIST', 'name' => 'Distribuidora da listagem']);
        $unit = ConsumerUnit::create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'number' => '123', 'street' => 'Rua teste', 'city' => 'São Paulo', 'state' => 'SP']);
        $project = SolarProject::create(['client_id' => $client->id, 'consumer_unit_id' => $unit->id, 'code' => 'PRJ-LIST', 'generation_type' => 'micro', 'modality' => 'autoconsumo_local']);
        foreach (['rascunho', 'em_preparacao', 'reprovado', 'aprovado', 'enviado', 'em_analise', 'conectado', 'cancelado'] as $status) {
            $process = HomologationProcess::create(['solar_project_id' => $project->id, 'distributor_id' => $distributor->id, 'code' => 'HML-'.$status, 'status' => $status]);
            if ($status === 'em_analise') {
                $process->deadlines()->create(['deadline_type' => DeadlineType::AccessOpinion, 'status' => 'OPEN', 'starts_at' => today(), 'due_at' => today()->addDays(15), 'days' => 15, 'day_count' => 'CALENDAR']);
            }
        }
    });
}

it('pagina os processos ativos sem esconder a reprovação que admite correção', function (): void {
    $tenant = tenantWithRoles();
    $user = userWithRole($tenant, SystemRole::TechnicalResponsible);
    processListRecords($tenant);
    processListRecords(tenantWithRoles());
    $this->actingAs($user, 'web');

    $first = $this->getJson('/api/processes?scope=active&per_page=5')->assertOk()
        ->assertJsonPath('meta.total', 6)->assertJsonPath('meta.last_page', 2)->assertJsonCount(5, 'data')->json('data');
    $second = $this->getJson('/api/processes?scope=active&per_page=5&page=2')->assertOk()
        ->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data')->json('data');
    $statuses = collect([...$first, ...$second])->pluck('status');
    expect($statuses)->toContain('reprovado')->not->toContain('cancelado', 'conectado');
    $analysis = collect($first)->firstWhere('status', 'em_analise');
    expect($analysis['open_deadline']['type'])->toBe('ACCESS_OPINION');
    expect($analysis['open_deadline']['due_at'])->toBe(today()->addDays(15)->toDateString());
});

it('permite consultar encerrados e preserva a listagem completa sem filtro', function (): void {
    $tenant = tenantWithRoles();
    $user = userWithRole($tenant, SystemRole::ReadOnly);
    processListRecords($tenant);
    $this->actingAs($user, 'web');
    $this->getJson('/api/processes?scope=closed')->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonFragment(['status' => 'conectado'])->assertJsonFragment(['status' => 'cancelado'])
        ->assertJsonMissing(['status' => 'reprovado']);
    $this->getJson('/api/processes')->assertOk()->assertJsonPath('meta.total', 8);
    $this->getJson('/api/processes?scope=invalid')->assertUnprocessable()->assertJsonValidationErrors('scope');
});

it('mantém o kanban alinhado às fases e contagens dos processos ativos do Dashboard', function (): void {
    $tenant = tenantWithRoles();
    $user = userWithRole($tenant, SystemRole::TechnicalResponsible);
    processListRecords($tenant);
    processListRecords(tenantWithRoles());
    app(TenantContext::class)->run($tenant, function (): void {
        foreach (['enviado' => WorkflowStage::ExternalAnalysis, 'em_analise' => WorkflowStage::ExternalAnalysis, 'aprovado' => WorkflowStage::Execution, 'conectado' => WorkflowStage::Connection] as $status => $stage) {
            HomologationProcess::where('status', $status)->update(['stage' => $stage]);
        }
    });
    $this->actingAs($user, 'web');
    $board = $this->getJson('/api/processes?board=1')->assertOk()->assertJsonCount(6, 'data')
        ->assertJsonFragment(['status' => 'reprovado'])->assertJsonMissing(['status' => 'conectado'])
        ->assertJsonMissing(['status' => 'cancelado'])->json('data');
    $dashboard = $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.totals.active', 6)->json('data.by_stage');
    $catalog = $this->getJson('/api/process-stages')->assertOk()->assertJsonCount(6, 'data.stages')->json('data.stages');
    expect(array_column($catalog, 'value'))->toBe(array_column($dashboard, 'stage'));
    expect(array_column($catalog, 'label'))->toBe(array_column($dashboard, 'label'));
    foreach ($dashboard as $column) {
        expect(collect($board)->where('stage', $column['stage'])->count())->toBe($column['total']);
    }
    $this->getJson('/api/processes?board=1&search=HML-aprovado')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.stage', 'EXECUTION');
});
