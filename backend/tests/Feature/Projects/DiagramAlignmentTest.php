<?php

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\SystemRole;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function diagramProject($test): array
{
    Storage::fake('local');
    $tenant = tenantWithRoles();
    $user = userWithRole($tenant, SystemRole::Administrator);
    $records = app(TenantContext::class)->run($tenant, function () {
        $client = Client::create(['type' => 'PF', 'document' => '52998224725', 'name' => 'Titular teste']);
        $distributor = Distributor::create(['code' => 'CELESC', 'name' => 'Distribuidora teste', 'integration_mode' => 'assisted']);
        $unit = ConsumerUnit::create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'number' => '4567', 'street' => 'Rua teste', 'city' => 'Florianópolis', 'state' => 'SC', 'supply_type' => 'trifasico']);
        $rt = TechnicalResponsible::create(['name' => 'Engenheiro teste', 'cpf' => '52998224725', 'council' => 'CREA', 'registration' => '123456', 'state' => 'SC', 'registration_status' => 'regular', 'active' => true]);
        $module = EquipmentItem::create(['type' => 'module', 'manufacturer' => 'Teste', 'model' => 'M550', 'power_w' => 550, 'active' => true]);
        $inverter = EquipmentItem::create(['type' => 'inverter', 'manufacturer' => 'Teste', 'model' => 'I5', 'power_w' => 5000, 'active' => true]);

        return compact('client', 'unit', 'rt', 'module', 'inverter');
    });
    extract($records);
    $test->actingAs($user, 'web');
    $project = $test->postJson('/api/projects', ['name' => 'Usina teste', 'client_id' => $client->uuid, 'consumer_unit_id' => $unit->uuid, 'technical_responsible_id' => $rt->uuid,
        'modality' => 'autoconsumo_local', 'installed_power_kwp' => 5.5, 'inverter_power_kw' => 5, 'equipment' => [['id' => $module->uuid, 'quantity' => 10], ['id' => $inverter->uuid, 'quantity' => 1]]])->assertCreated()->json('data');
    $technical = ['name' => 'Usina teste', 'installation_type' => 'rooftop', 'connection' => ['connection_point' => 'Quadro principal', 'supply_voltage' => 380, 'phase_configuration' => 'trifasico', 'main_breaker_a' => 40, 'installed_load_kw' => 10],
        'arrays' => [['module_model_id' => $module->uuid, 'module_quantity' => 10, 'strings_quantity' => 2, 'modules_per_string' => 5, 'azimuth' => 0, 'tilt' => 15]],
        'inverters' => [['inverter_model_id' => $inverter->uuid, 'quantity' => 1, 'nominal_ac_kw' => 5, 'connection_voltage' => 380, 'protection_config_json' => ['description' => 'Proteção contra sobretensão e sobrecorrente']]],
        'storage' => [], 'compensation' => ['mode' => 'autoconsumo_local', 'allocation_rule' => 'percentage', 'units' => [['consumer_unit_id' => $unit->uuid, 'percentage' => 100]]]];
    $test->putJson('/api/projects/'.$project['id'].'/technical-data', $technical)->assertOk();
    $process = $project['process']['id'];
    $documents = [];
    foreach (['formulario_solicitacao', 'art_trt', 'diagrama_unifilar', 'memorial_descritivo', 'datasheet_modulo', 'datasheet_inversor', 'certificado_inversor', 'documento_titular'] as $type) {
        $documents[$type] = diagramDocument($test, $process, $type);
    }
    $test->postJson('/api/projects/'.$project['id'].'/responsibility-terms', ['type' => 'ART', 'number' => 'ART-01', 'issued_at' => today()->toDateString(), 'technical_responsible_id' => $rt->uuid, 'file_id' => $documents['art_trt']])->assertOk();
    $test->postJson('/api/processes/'.$process.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    $test->postJson('/api/processes/'.$process.'/transitions', ['status' => 'pronto_para_envio'])->assertOk();

    return compact('tenant', 'user', 'project', 'process', 'technical', 'documents');
}

function diagramDocument($test, string $process, string $type): string
{
    $id = $test->post('/api/processes/'.$process.'/documents', ['document_type' => $type, 'file' => UploadedFile::fake()->createWithContent($type.'.pdf', "%PDF-1.4\n".$type.random_int(1, 999999))], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $test->postJson('/api/documents/'.$id.'/review', ['review_status' => 'aprovado'])->assertOk();

    return $id;
}

it('registra o cenário completo com versões imutáveis, reenvio, pendência, orçamento e vistoria', function () {
    extract(diagramProject($this));
    $base = '/api/processes/'.$process;
    $payload = ['kind' => 'initial', 'idempotency_key' => 'initial-object-01', 'change_reason' => 'Dossiê inicial revisado'];
    $submission = $this->postJson($base.'/submissions', $payload)->assertCreated()->json('data');
    $this->postJson($base.'/submissions', $payload)->assertCreated()->assertJsonPath('data.id', $submission['id']);
    $this->assertDatabaseCount('project_versions', 1);
    $this->assertDatabaseCount('submissions', 1);
    $export = $this->get($base.'/submissions/'.$submission['id'].'/dossier')->assertOk()->assertDownload();
    $archive = new ZipArchive;
    $archive->open($export->baseResponse->getFile()->getPathname());
    expect($archive->numFiles)->toBe(9);
    expect(json_decode($archive->getFromName('manifesto.json'), true)['submission']['request_hash'])->toBe($submission['request_hash']);
    $archive->close();
    unlink($export->baseResponse->getFile()->getPathname());
    $this->postJson($base.'/submissions/'.$submission['id'].'/fail', ['reason' => 'Portal temporariamente indisponível'])->assertOk();
    $this->getJson($base)->assertOk()->assertJsonPath('data.status', 'pronto_para_envio');
    $receipt = diagramDocument($this, $process, 'comprovante_envio');
    $confirmation = ['protocol_number' => 'TEST-001', 'external_receipt' => 'REC-001', 'receipt_document_id' => $receipt];
    $this->postJson($base.'/submissions/'.$submission['id'].'/confirm', $confirmation)->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson($base.'/submissions/'.$submission['id'].'/confirm', $confirmation)->assertOk();
    $this->assertDatabaseCount('integration_events', 3);
    $this->getJson($base)->assertOk()->assertJsonPath('data.status', 'enviado');
    $this->postJson($base.'/transitions', ['status' => 'em_analise'])->assertOk();
    $pending = $this->postJson($base.'/external-pendencies', ['code' => 'P01', 'description' => 'Complementar memorial'])->assertOk()->json('data.pending_items.0.id');
    $technical['arrays'][0]['tilt'] = 20;
    $this->putJson('/api/projects/'.$project['id'].'/technical-data', $technical)->assertOk();
    $this->getJson('/api/projects/'.$project['id'].'/versions/'.$submission['version_id'])->assertOk()->assertJsonPath('data.snapshot.technical_data.arrays.0.tilt', '15.00');
    $response = diagramDocument($this, $process, 'memorial_descritivo');
    $this->putJson($base.'/external-pendencies/'.$pending.'/response', ['response_document_id' => $response])->assertOk();
    $correction = $this->postJson($base.'/submissions', ['kind' => 'correction', 'idempotency_key' => 'correction-object-01', 'change_reason' => 'Memorial complementado', 'pending_item_id' => $pending])->assertCreated()->json('data');
    expect($correction['version'])->toBe(2);
    $this->postJson($base.'/submissions/'.$correction['id'].'/confirm', $confirmation)->assertOk();
    $this->getJson($base.'/tracking')->assertOk()->assertJsonPath('data.pending_items.0.status', 'respondida');
    $this->postJson($base.'/transitions', ['status' => 'em_analise'])->assertOk();
    $this->postJson($base.'/transitions', ['status' => 'aprovado'])->assertOk();
    $budget = diagramDocument($this, $process, 'orcamento_conexao');
    $this->postJson($base.'/connection-budgets', ['issued_at' => today()->toDateString(), 'amount' => 2500, 'works_required' => true, 'document_id' => $budget])->assertOk()->assertJsonPath('data.budgets.0.amount', 2500);
    $inspectionSubmission = $this->postJson($base.'/submissions', ['kind' => 'inspection', 'idempotency_key' => 'inspection-object-01', 'change_reason' => 'Solicitação de vistoria'])->assertCreated()->json('data.id');
    $this->postJson($base.'/submissions/'.$inspectionSubmission.'/confirm', $confirmation)->assertOk();
    $this->postJson($base.'/transitions', ['status' => 'conectado'])->assertUnprocessable();
    $report = diagramDocument($this, $process, 'relatorio_vistoria');
    $date = now()->subMinute()->toIso8601String();
    $this->postJson($base.'/inspections', ['submission_id' => $inspectionSubmission, 'requested_at' => $date, 'performed_at' => $date, 'status' => 'aprovada', 'connection_approved_at' => $date, 'report_document_id' => $report])->assertOk();
    $this->postJson($base.'/transitions', ['status' => 'conectado'])->assertOk()->assertJsonPath('data.status', 'conectado');
    $this->postJson('/api/projects/'.$project['id'].'/processes')->assertCreated();
    $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertOk()->assertJsonCount(2, 'data.processes')->assertJsonCount(3, 'data.versions');
});

it('protege versões e eventos também contra alterações diretas no PostgreSQL', function () {
    extract(diagramProject($this));
    $submission = $this->postJson('/api/processes/'.$process.'/submissions', ['kind' => 'initial', 'idempotency_key' => 'immutability-01', 'change_reason' => 'Teste de integridade'])->assertCreated()->json('data');
    expect(fn () => DB::transaction(fn () => DB::table('project_versions')->update(['change_reason' => 'alterada'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('integration_events')->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('submissions')->update(['request_hash' => str_repeat('0', 64)])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('process_documents')->update(['sha256' => str_repeat('0', 64)])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('project_version_documents')->delete()))->toThrow(QueryException::class);
    $this->getJson('/api/projects/'.$project['id'].'/versions/'.$submission['version_id'])->assertOk()->assertJsonPath('data.change_reason', 'Teste de integridade');
});

it('aplica etapas configuráveis e requisitos condicionais com checklist persistido', function () {
    extract(diagramProject($this));
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    $this->postJson('/api/workflow-stages', ['code' => 'revisao_tecnica', 'name' => 'Revisão técnica', 'order' => 2, 'stage_type' => 'em_preparacao', 'next' => ['pronto_para_envio', 'em_preparacao'], 'active' => true])->assertOk();
    $configuration = $this->getJson('/api/workflow-configuration')->assertOk()->json('data');
    $stage = collect($configuration['stages'])->firstWhere('code', 'em_preparacao');
    $this->putJson('/api/workflow-stages/'.$stage['id'], [...$stage, 'next' => ['revisao_tecnica']])->assertOk();
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'revisao_tecnica'])->assertOk()->assertJsonPath('data.stage_code', 'revisao_tecnica')->assertJsonPath('data.status', 'em_preparacao');
    $this->postJson('/api/requirements', ['code' => 'conferencia_campo', 'name' => 'Conferência de campo', 'required_document_type' => null, 'active' => true, 'conditions' => ['min_power_kw' => 5, 'installation_type' => ['rooftop']]])->assertOk();
    $processData = $this->getJson('/api/processes/'.$process)->assertOk()->json('data');
    $item = collect($processData['checklist'])->firstWhere('label', 'Conferência de campo');
    expect($item)->not->toBeNull();
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'pronto_para_envio'])->assertUnprocessable();
    $this->postJson('/api/checklist-items/'.$item['id'].'/review', ['status' => 'aprovado', 'notes' => 'Conferência realizada em campo'])->assertOk();
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'pronto_para_envio'])->assertOk();
    $this->assertDatabaseHas('process_checklist', ['status' => 'aprovado', 'validated_by' => $user->id]);
});

it('aceita documentos do projeto e bloqueia arquivo vencido ou adulterado', function () {
    extract(diagramProject($this));
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    $document = $this->post('/api/projects/'.$project['id'].'/documents', ['document_type' => 'outro', 'issued_at' => today()->subDays(2)->toDateString(), 'expires_at' => today()->subDay()->toDateString(), 'file' => UploadedFile::fake()->createWithContent('vencido.pdf', "%PDF-1.4\nDocumento vencido")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson('/api/documents/'.$document.'/review', ['review_status' => 'aprovado'])->assertUnprocessable();
    $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertOk()->assertJsonCount(9, 'data.documents');
    $stored = ProcessDocument::where('uuid', $documents['art_trt'])->firstOrFail();
    Storage::disk('local')->put($stored->storage_path, 'Conteúdo adulterado');
    $this->get('/api/documents/'.$stored->uuid.'/download', ['Accept' => 'application/json'])->assertConflict();
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'pronto_para_envio'])->assertUnprocessable();
    $technical['connection']['solar_project_id'] = 9999;
    $this->putJson('/api/projects/'.$project['id'].'/technical-data', $technical)->assertUnprocessable()->assertJsonValidationErrors('connection');
});

it('valida rateio, quantidades e a propriedade dos documentos e processos', function () {
    extract(diagramProject($this));
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    $storedProject = SolarProject::where('uuid', $project['id'])->firstOrFail();
    $storedProject->update(['has_battery' => true]);
    $this->postJson('/api/processes/'.$process.'/transitions', ['status' => 'pronto_para_envio'])->assertUnprocessable();
    $issues = $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertOk()->json('data.issues');
    expect($issues)->toContain('Cadastre as baterias do sistema de armazenamento informado no projeto.');
    $storedProject->update(['has_battery' => false]);
    $technical['compensation']['units'][0]['percentage'] = 90;
    $this->putJson('/api/projects/'.$project['id'].'/technical-data', $technical)->assertUnprocessable();
    $technical['compensation']['units'][0]['percentage'] = 100;
    $technical['arrays'][0]['module_quantity'] = 11;
    $this->putJson('/api/projects/'.$project['id'].'/technical-data', $technical)->assertUnprocessable();
    $otherTenant = tenantWithRoles();
    $other = userWithRole($otherTenant, SystemRole::Administrator);
    $this->flushSession();
    app('auth')->guard('sanctum')->forgetUser();
    $this->actingAs($other, 'web');
    $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertNotFound();
    $this->getJson('/api/processes/'.$process.'/tracking')->assertNotFound();
    $this->getJson('/api/documents/'.$documents['art_trt'].'/download')->assertNotFound();
});

it('impede resposta antiga fora do dossiê e preserva a etapa inicial', function () {
    extract(diagramProject($this));
    $base = '/api/processes/'.$process;
    $configuration = $this->getJson('/api/workflow-configuration')->assertOk()->json('data');
    $initial = collect($configuration['stages'])->firstWhere('code', 'rascunho');
    $this->putJson('/api/workflow-stages/'.$initial['id'], [...$initial, 'active' => false])->assertUnprocessable();
    $this->putJson('/api/workflow-stages/'.$initial['id'], [...$initial, 'stage_type' => 'em_preparacao'])->assertUnprocessable();

    $initialSubmission = $this->postJson($base.'/submissions', ['kind' => 'initial', 'idempotency_key' => 'before-response-01', 'change_reason' => 'Primeiro envio revisado'])->assertCreated()->json('data');
    $initialReceipt = diagramDocument($this, $process, 'comprovante_envio');
    $this->postJson($base.'/submissions/'.$initialSubmission['id'].'/confirm', ['protocol_number' => 'TEST-TRACE', 'external_receipt' => 'REC-FIRST', 'receipt_document_id' => $initialReceipt])->assertOk();
    $this->postJson($base.'/transitions', ['status' => 'em_analise'])->assertOk();

    $pending = $this->postJson($base.'/external-pendencies', ['code' => 'P02', 'description' => 'Atualizar memorial'])->assertOk()->json('data.pending_items.0.id');
    $this->putJson($base.'/external-pendencies/'.$pending.'/response', ['response_document_id' => $documents['memorial_descritivo']])->assertOk();
    $updated = diagramDocument($this, $process, 'memorial_descritivo');
    $payload = ['kind' => 'correction', 'idempotency_key' => 'response-trace-01', 'change_reason' => 'Memorial atualizado', 'pending_item_id' => $pending];
    $this->postJson($base.'/submissions', $payload)->assertUnprocessable();
    $this->assertDatabaseCount('project_versions', 1);
    $this->putJson($base.'/external-pendencies/'.$pending.'/response', ['response_document_id' => $updated])->assertOk();
    $submission = $this->postJson($base.'/submissions', $payload)->assertCreated()->json('data');
    $receipt = diagramDocument($this, $process, 'comprovante_envio');
    $this->putJson($base.'/external-pendencies/'.$pending.'/response', ['response_document_id' => $documents['memorial_descritivo']])->assertOk();
    $this->postJson($base.'/submissions/'.$submission['id'].'/confirm', ['protocol_number' => 'TEST-TRACE', 'external_receipt' => 'REC-TRACE', 'receipt_document_id' => $receipt])->assertConflict();
    $tracking = $this->getJson($base.'/tracking')->assertOk()->assertJsonPath('data.pending_items.0.status', 'aberta')->json('data');
    expect(collect($tracking['submissions'])->firstWhere('id', $submission['id'])['status'])->toBe('prepared');
});

it('encerra a atribuição importada ao remover o responsável pelo processo', function () {
    extract(diagramProject($this));
    $record = HomologationProcess::where('uuid', $process)->firstOrFail();
    $record->update(['assigned_user_id' => $user->id]);
    $assignment = $record->assignments()->create(['user_id' => $user->id, 'role' => 'homologador', 'active' => true]);
    $this->patchJson('/api/processes/'.$process, ['assigned_user_id' => null])->assertOk()->assertJsonPath('data.assignee', null);
    expect($assignment->refresh()->active)->toBeFalse();
    expect($assignment->revoked_at)->not->toBeNull();
});
