<?php

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Projects\ProjectEvaluator;
use App\Domain\Rules\Models\RuleDecision;
use App\Domain\Tenancy\TenantContext;
use Database\Seeders\RulesSeeder;
use Illuminate\Http\UploadedFile;

it('aceita o formulário com RTs separados sem perder os dados técnicos e o rateio existentes', function () {
    extract(diagramProject($this));
    $this->seed(RulesSeeder::class);
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    $payload = app(TenantContext::class)->run($tenant, function () use ($project) {
        $p = SolarProject::where('uuid', $project['id'])->firstOrFail();

        return ['name' => $p->name, 'client_id' => $p->client->uuid, 'consumer_unit_id' => $p->consumerUnit->uuid,
            'compensation_mode' => 'LOCAL_SELF_CONSUMPTION', 'initial_protocol' => 'INITIAL-001',
            'project_rt' => ['id' => $p->technicalResponsible->uuid, 'art_number' => 'ART-01'],
            'execution_rt' => ['id' => $p->technicalResponsible->uuid, 'art_number' => 'ART-EXEC-01'],
            'equipment' => $p->equipment->map(fn ($e) => ['id' => $e->uuid, 'quantity' => (int) $e->pivot->getAttribute('quantity')])->all()];
    });
    $this->putJson('/api/projects/'.$project['id'], $payload)->assertOk()
        ->assertJsonPath('data.classification', 'MICRO')->assertJsonPath('data.considered_power_kw', 5)
        ->assertJsonFragment(['purpose' => 'EXECUTION']);
    $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertOk()
        ->assertJsonCount(1, 'data.technical_data.compensation.units')->assertJsonPath('data.technical_data.arrays.0.module_quantity', 10);
    $moduleId = app(TenantContext::class)->run($tenant, fn () => EquipmentItem::where('type', 'module')->firstOrFail()->uuid);
    $payload['equipment'][array_search($moduleId, array_column($payload['equipment'], 'id'), true)]['quantity'] = 20;
    $this->putJson('/api/projects/'.$project['id'], $payload)->assertOk()->assertJsonPath('data.modules_power_kwp', 11);
    $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertOk()->assertJsonPath('data.technical_data.arrays.0.module_quantity', 20);
    $this->getJson('/api/projects/'.$project['id'].'/evaluation')->assertOk()->assertJsonPath('data.classification.rule_code', 'CLASS_MICRO');
    expect(RuleDecision::count())->toBeGreaterThanOrEqual(4);
});

it('mantém status, fase e pendências externas sincronizados nas ações novas', function () {
    extract(diagramProject($this));
    $base = '/api/processes/'.$process;
    $submission = $this->postJson($base.'/submissions', ['kind' => 'initial', 'idempotency_key' => 'merge-initial-01', 'change_reason' => 'Envio integrado'])->assertCreated()->json('data.id');
    $receipt = diagramDocument($this, $process, 'comprovante_envio');
    $this->postJson($base.'/submissions/'.$submission.'/confirm', ['protocol_number' => 'MERGE-001', 'receipt_document_id' => $receipt, 'external_receipt' => 'RECEIPT-001'])->assertOk();
    $this->postJson($base.'/actions/register-correction', ['items' => ['Corrigir memorial', 'Complementar diagrama']])->assertOk()
        ->assertJsonPath('data.status', 'pendencia_distribuidora')->assertJsonPath('data.stage', 'CORRECTION')->assertJsonCount(2, 'data.pendencies');
    $this->getJson($base.'/tracking')->assertOk()->assertJsonCount(2, 'data.pending_items');
    $this->postJson($base.'/actions/approve-access', ['network_work_status' => 'NOT_REQUIRED'])->assertStatus(409);
});

it('usa documentos do equipamento nas regras e na versão imutável do projeto', function () {
    extract(diagramProject($this));
    $equipment = app(TenantContext::class)->run($tenant, fn () => EquipmentItem::where('type', 'inverter')->firstOrFail());
    $id = $this->post('/api/documents', ['owner_type' => 'equipment', 'owner_id' => $equipment->uuid, 'document_type' => 'INVERTER_TEST_REPORT',
        'file' => UploadedFile::fake()->createWithContent('ensaio.pdf', "%PDF-1.4\nEquipment report")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson('/api/documents/'.$id.'/review', ['review_status' => 'aprovado'])->assertOk();
    $this->seed(RulesSeeder::class);
    $this->getJson('/api/projects/'.$project['id'].'/evaluation')->assertOk();
    $submission = $this->postJson('/api/processes/'.$process.'/submissions', ['kind' => 'initial', 'idempotency_key' => 'merge-equipment-01', 'change_reason' => 'Inclui certificado do catálogo'])->assertCreated()->json('data');
    $snapshot = $this->getJson('/api/projects/'.$project['id'].'/versions/'.$submission['version_id'])->assertOk()->json('data.snapshot.documents');
    expect(collect($snapshot)->pluck('id'))->toContain($id);
    app(TenantContext::class)->run($tenant, fn () => expect(DocumentLink::where('linkable_type', 'equipment')->count())->toBe(1));
});

it('exige relatório aprovado e conexão comprovada para concluir pelas novas ações', function () {
    extract(diagramProject($this));
    $this->seed(RulesSeeder::class);
    $inverter = app(TenantContext::class)->run($tenant, function () use ($project) {
        $p = SolarProject::where('uuid', $project['id'])->firstOrFail();
        $p->responsibilities()->create(['purpose' => 'EXECUTION', 'technical_responsible_id' => $p->technical_responsible_id, 'art_number' => 'EXEC-01']);
        app(ProjectEvaluator::class)->evaluate($p);

        return $p->equipment->firstWhere('type', 'inverter');
    });
    $testReport = $this->post('/api/documents', ['owner_type' => 'equipment', 'owner_id' => $inverter->uuid, 'document_type' => 'INVERTER_TEST_REPORT',
        'file' => UploadedFile::fake()->createWithContent('ensaio.pdf', "%PDF-1.4\nCertificado do inversor")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson('/api/documents/'.$testReport.'/review', ['review_status' => 'aprovado'])->assertOk();
    $base = '/api/processes/'.$process;
    $first = $this->postJson($base.'/submissions', ['kind' => 'initial', 'idempotency_key' => 'merge-full-initial', 'change_reason' => 'Envio inicial'])->assertCreated()->json('data.id');
    $receipt = diagramDocument($this, $process, 'comprovante_envio');
    $confirmation = ['protocol_number' => 'MERGE-FULL', 'receipt_document_id' => $receipt, 'external_receipt' => 'RECEIPT-FULL'];
    $this->postJson($base.'/submissions/'.$first.'/confirm', $confirmation)->assertOk();
    $this->postJson($base.'/actions/approve-access', ['network_work_status' => 'NOT_REQUIRED'])->assertOk()
        ->assertJsonPath('data.status', 'aprovado')->assertJsonPath('data.stage', 'EXECUTION');
    $execution = $this->postJson($base.'/actions/execution', ['started_at' => today()->toDateString(), 'completed_at' => today()->toDateString()])->assertOk()->json('data.execution.id');
    $this->postJson($base.'/submissions', ['kind' => 'inspection', 'idempotency_key' => 'merge-full-inspection', 'change_reason' => 'Solicitação real de vistoria'])->assertUnprocessable();
    $art = $this->post('/api/documents', ['owner_type' => 'execution', 'owner_id' => $execution, 'document_type' => 'EXECUTION_ART',
        'file' => UploadedFile::fake()->createWithContent('execucao.pdf', "%PDF-1.4\nART de execução")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson('/api/documents/'.$art.'/review', ['review_status' => 'aprovado'])->assertOk();
    $inspectionSubmission = $this->postJson($base.'/submissions', ['kind' => 'inspection', 'idempotency_key' => 'merge-full-inspection', 'change_reason' => 'Solicitação real de vistoria'])->assertCreated()->json('data.id');
    $this->postJson($base.'/submissions/'.$inspectionSubmission.'/confirm', $confirmation)->assertOk();
    $response = $this->postJson($base.'/actions/request-inspection', [])->assertOk();
    $inspection = $response->json('data.inspections.0.id');
    $this->postJson('/api/inspections/'.$inspection.'/result', ['approved' => true])->assertUnprocessable();
    $report = diagramDocument($this, $process, 'relatorio_vistoria');
    $this->postJson('/api/inspections/'.$inspection.'/result', ['approved' => true, 'performed_at' => now()->toIso8601String(), 'report_document_id' => $report])->assertOk()->assertJsonPath('data.stage', 'CONNECTION');
    $this->postJson($base.'/actions/complete', [])->assertStatus(422);
    $this->postJson($base.'/actions/connection-event', ['type' => 'METER_INSTALLED', 'occurred_at' => now()->toIso8601String(), 'meter_number' => 'METER-001'])->assertOk();
    $this->postJson($base.'/actions/connection-event', ['type' => 'SYSTEM_CONNECTED', 'occurred_at' => now()->toIso8601String()])->assertOk();
    $this->postJson($base.'/actions/complete', [])->assertOk()->assertJsonPath('data.status', 'conectado');
    app(TenantContext::class)->run($tenant, fn () => expect(HomologationProcess::where('uuid',$process)->firstOrFail()->history()->where('to_status','conectado')->count())->toBe(1));
});
