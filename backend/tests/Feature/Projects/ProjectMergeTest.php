<?php

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Documents\DocumentUploader;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Enums\ClientRequestStatus;
use App\Domain\Projects\Models\ClientRequest;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;
use Database\Seeders\RulesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->tenant = tenantWithRoles();
    $this->admin = userWithRole($this->tenant, SystemRole::Administrator);
    $this->engineer = userWithRole($this->tenant, SystemRole::TechnicalResponsible);
    $this->records = app(TenantContext::class)->run($this->tenant, function (): array {
        $client = Client::create(['type' => 'PF', 'document' => '52998224725', 'name' => 'Titular do projeto']);
        $distributor = Distributor::create(['code' => 'CELESC', 'name' => 'Distribuidora teste']);
        $unit = ConsumerUnit::create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'number' => '123', 'street' => 'Rua teste', 'city' => 'Florianópolis', 'state' => 'SC']);
        $otherUnit = ConsumerUnit::create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'number' => '456', 'street' => 'Rua teste', 'city' => 'Florianópolis', 'state' => 'SC']);
        $rt = TechnicalResponsible::create(['user_id' => $this->engineer->id, 'name' => 'Engenheiro teste', 'council' => 'CREA', 'registration' => '123456', 'state' => 'SC', 'active' => true]);
        $module = EquipmentItem::create(['type' => 'module', 'manufacturer' => 'Teste', 'model' => 'M550', 'power_w' => 550, 'active' => true]);
        $inverter = EquipmentItem::create(['type' => 'inverter', 'manufacturer' => 'Teste', 'model' => 'I5', 'power_w' => 5500, 'nominal_ac_power_kw' => 5, 'active' => true]);

        return compact('client', 'unit', 'otherUnit', 'rt', 'module', 'inverter');
    });
    extract($this->records);
    $this->payload = [
        'name' => 'Projeto após merge', 'installation_type' => 'rooftop',
        'client_id' => $client->uuid, 'consumer_unit_id' => $unit->uuid,
        'compensation_mode' => 'LOCAL_SELF_CONSUMPTION', 'initial_protocol' => 'PROTO-001',
        'project_rt' => ['id' => $rt->uuid, 'art_number' => 'ART-PROJETO'],
        'execution_rt' => ['id' => $rt->uuid, 'art_number' => 'ART-EXECUCAO'],
        'equipment' => [['id' => $module->uuid, 'quantity' => 10], ['id' => $inverter->uuid, 'quantity' => 1]],
        'compensation_units' => [['consumer_unit_id' => $unit->uuid, 'percentage' => 100]],
    ];
    $this->seed(RulesSeeder::class);
    $this->actingAs($this->admin, 'web');
});

it('lista, cria e edita o formulário atual mantendo um único processo e os dados técnicos', function (): void {
    $project = $this->postJson('/api/projects', $this->payload)->assertCreated()
        ->assertJsonPath('data.modules_power_kwp', 5.5)
        ->assertJsonPath('data.inverters_power_kw', 5)
        ->assertJsonPath('data.classification', 'MICRO')
        ->assertJsonPath('data.initial_protocol', 'PROTO-001')
        ->assertJsonPath('data.process.status', 'rascunho')
        ->assertJsonPath('data.process.stage', 'PREPARATION')
        ->assertJsonCount(1, 'data.processes')
        ->assertJsonCount(2, 'data.responsibilities')
        ->assertJsonCount(1, 'data.compensation_units')->json('data');

    $this->getJson('/api/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $project['id']);
    $this->getJson('/api/projects/'.$project['id'])->assertOk()->assertJsonPath('data.process.id', $project['process']['id']);
    $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertOk()
        ->assertJsonPath('data.technical_data.arrays.0.module_quantity', 10)
        ->assertJsonPath('data.technical_data.inverters.0.nominal_ac_kw', '5.000');

    $this->payload['equipment'][0]['quantity'] = 20;
    $this->putJson('/api/projects/'.$project['id'], $this->payload)->assertOk()
        ->assertJsonPath('data.modules_power_kwp', 11)
        ->assertJsonPath('data.process.id', $project['process']['id']);
    $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertOk()
        ->assertJsonPath('data.technical_data.arrays.0.module_quantity', 20);
    $this->assertDatabaseCount('solar_projects', 1);
    $this->assertDatabaseCount('homologation_processes', 1);
    $this->assertDatabaseCount('process_status_histories', 1);
    $this->assertDatabaseCount('process_stage_history', 1);
    $this->assertDatabaseCount('process_assignments', 1);
});

it('converte a solicitação uma única vez reaproveitando documentos e mantendo o responsável do processo', function (bool $assigned): void {
    [$source, $current] = app(TenantContext::class)->run($this->tenant, function () use ($assigned): array {
        $source = ClientRequest::create(['client_id' => $this->records['client']->id, 'consumer_unit_id' => $this->records['unit']->id, 'code' => 'SOL-001', 'system' => []]);
        if ($assigned) {
            $source->forceFill(['technical_responsible_id' => $this->records['rt']->id])->save();
        }
        $uploader = app(DocumentUploader::class);
        $uploader->upload('client_request', $source, 'HOLDER_ID', UploadedFile::fake()->createWithContent('titular.pdf', "%PDF-1.4\nVersão inicial"), $this->admin->id);
        $current = $uploader->upload('client_request', $source, 'HOLDER_ID', UploadedFile::fake()->createWithContent('titular.pdf', "%PDF-1.4\nVersão atual"), $this->admin->id);

        return [$source, $current];
    });
    $payload = [...$this->payload, 'client_request_id' => $source->uuid];
    $project = $this->postJson('/api/projects', $payload)->assertCreated()->json('data');

    app(TenantContext::class)->run($this->tenant, function () use ($source, $current, $project, $assigned): void {
        $model = SolarProject::where('uuid', $project['id'])->firstOrFail();
        $process = HomologationProcess::where('uuid', $project['process']['id'])->firstOrFail();
        $assignee = $assigned ? $this->engineer->id : $this->admin->id;
        expect($source->fresh()->status)->toBe(ClientRequestStatus::Converted)
            ->and($source->fresh()->solar_project_id)->toBe($model->id)
            ->and($process->assigned_user_id)->toBe($assignee)
            ->and($process->assignments()->where('active', true)->pluck('user_id')->all())->toBe([$assignee])
            ->and($process->timeline()->where('type', 'CREATED')->count())->toBe(1)
            ->and($process->timeline()->where('type', 'CLIENT_REQUEST_CONVERTED')->count())->toBe(1)
            ->and(DocumentLink::where('linkable_type', 'project')->where('linkable_id', $model->id)->pluck('document_id')->all())->toBe([$current->id]);
    });
    $this->getJson('/api/client-requests/'.$source->uuid)->assertOk()
        ->assertJsonPath('data.project.id', $project['id'])
        ->assertJsonPath('data.documents.0.id', $current->uuid);
    $this->postJson('/api/projects', $payload)->assertUnprocessable()->assertJsonPath('code', 'request_closed');
    $this->assertDatabaseCount('solar_projects', 1);
    $this->assertDatabaseCount('homologation_processes', 1);
})->with(['RT atribuído' => true, 'sem RT atribuído' => false]);

it('rejeita conversão com outra UC ou solicitação encerrada sem criar registros parciais', function (string $reason): void {
    $source = app(TenantContext::class)->run($this->tenant, function () use ($reason): ClientRequest {
        $source = ClientRequest::create(['client_id' => $this->records['client']->id, 'consumer_unit_id' => $this->records[$reason === 'request_unit_mismatch' ? 'otherUnit' : 'unit']->id, 'code' => 'SOL-001', 'system' => []]);
        if ($reason === 'request_closed') {
            $source->forceFill(['status' => ClientRequestStatus::Cancelled])->save();
        }

        return $source;
    });
    $this->postJson('/api/projects', [...$this->payload, 'client_request_id' => $source->uuid])
        ->assertUnprocessable()->assertJsonPath('code', $reason);
    $this->assertDatabaseCount('solar_projects', 0);
    $this->assertDatabaseCount('homologation_processes', 0);
    $this->assertDatabaseCount('project_equipment', 0);
})->with(['request_unit_mismatch', 'request_closed']);
