<?php

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Rules\Models\RequirementRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function submissionRequirement(string $code, array $attributes = []): RequirementRule
{
    return RequirementRule::create([
        'rule_code' => $code, 'version' => 1, 'priority' => 100, 'description' => 'Conferência de envio',
        'active' => true, 'effective_from' => today()->subDay()->toDateString(), 'conditions' => [],
        'phase' => 'SUBMISSION', 'kind' => 'DOCUMENT', 'document_type' => 'ENERGY_BILL',
        'outcome' => 'REQUIRED', 'label' => 'Conta de energia revisada', 'reason' => 'Identificar a unidade consumidora.',
        'waivable' => false, 'source_reference' => 'Requisito interno para testes de envio', ...$attributes,
    ]);
}

it('bloqueia pronto para envio até completar e aprovar também os requisitos condicionais de envio', function (): void {
    extract(diagramProject($this));
    $base = '/api/processes/'.$process;
    $this->postJson($base.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    submissionRequirement('REQ_TEST_ENERGY_BILL');
    $before = $this->getJson($base)->assertOk()->json('data');
    $ready = collect($before['allowed_transitions'])->firstWhere('stage_type', 'pronto_para_envio');
    expect($ready['available'])->toBeFalse()
        ->and(implode(' ', $ready['reasons']))->toContain('Conta de energia revisada')
        ->and(implode(' ', $before['readiness_issues']))->toContain('Conta de energia revisada');
    $this->postJson($base.'/transitions', ['status' => 'pronto_para_envio'])->assertUnprocessable()->assertJsonPath('code', 'process_not_ready');
    $this->getJson($base)->assertOk()->assertJsonPath('data.status', 'em_preparacao')->assertJsonCount(count($before['history']), 'data.history');

    $file = UploadedFile::fake()->createWithContent('conta.pdf', "%PDF-1.4\nConta de energia atual");
    $document = $this->post($base.'/documents', ['document_type' => 'ENERGY_BILL', 'file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson($base.'/transitions', ['status' => 'pronto_para_envio'])->assertUnprocessable();
    $this->postJson('/api/documents/'.$document.'/review', ['review_status' => 'reprovado', 'review_notes' => 'Conta ilegível'])->assertOk();
    $this->postJson($base.'/transitions', ['status' => 'pronto_para_envio'])->assertUnprocessable();
    $document = $this->post($base.'/documents', ['document_type' => 'ENERGY_BILL', 'file' => UploadedFile::fake()->createWithContent('conta-revisada.pdf', "%PDF-1.4\nConta de energia legível")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson('/api/documents/'.$document.'/review', ['review_status' => 'aprovado'])->assertOk();
    $complete = $this->getJson($base)->assertOk()->assertJsonPath('data.readiness_issues', [])->json('data');
    expect(collect($complete['allowed_transitions'])->firstWhere('stage_type', 'pronto_para_envio')['available'])->toBeTrue();
    $this->postJson($base.'/transitions', ['status' => 'pronto_para_envio'])->assertOk()->assertJsonPath('data.status', 'pronto_para_envio');

    $this->postJson($base.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    $stored = ProcessDocument::where('uuid', $document)->firstOrFail();
    Storage::disk('local')->put($stored->storage_path, 'Arquivo adulterado');
    $incomplete = $this->getJson($base)->assertOk()->json('data');
    expect(collect($incomplete['allowed_transitions'])->firstWhere('stage_type', 'pronto_para_envio')['available'])->toBeFalse();
    $this->postJson($base.'/transitions', ['status' => 'pronto_para_envio'])->assertUnprocessable()->assertJsonPath('code', 'process_not_ready');
});

it('não bloqueia o envio por requisitos opcionais, inativos, não aplicáveis ou de fases posteriores', function (): void {
    extract(diagramProject($this));
    $base = '/api/processes/'.$process;
    $this->postJson($base.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    submissionRequirement('REQ_TEST_OPTIONAL', ['outcome' => 'OPTIONAL']);
    submissionRequirement('REQ_TEST_INACTIVE', ['active' => false]);
    submissionRequirement('REQ_TEST_STORAGE', ['conditions' => ['fact' => 'has_storage', 'op' => 'eq', 'value' => true]]);
    submissionRequirement('REQ_TEST_INSPECTION', ['phase' => 'INSPECTION_REQUEST']);
    $this->getJson($base)->assertOk()->assertJsonPath('data.readiness_issues', []);
    $this->postJson($base.'/transitions', ['status' => 'pronto_para_envio'])->assertOk()->assertJsonPath('data.status', 'pronto_para_envio');
});

it('aplica o mesmo bloqueio a uma situação personalizada baseada em pronto para envio', function (): void {
    extract(diagramProject($this));
    $base = '/api/processes/'.$process;
    $this->postJson($base.'/transitions', ['status' => 'em_preparacao'])->assertOk();
    $this->postJson('/api/workflow-stages', ['code' => 'preparado_envio', 'name' => 'Preparado para o envio', 'order' => 4, 'stage_type' => 'pronto_para_envio', 'next' => ['em_preparacao', 'enviado'], 'active' => true])->assertOk();
    $stages = $this->getJson('/api/workflow-configuration')->assertOk()->json('data.stages');
    $preparation = collect($stages)->firstWhere('code', 'em_preparacao');
    $this->putJson('/api/workflow-stages/'.$preparation['id'], [...$preparation, 'next' => ['preparado_envio']])->assertOk();
    submissionRequirement('REQ_TEST_CUSTOM_READY');
    $data = $this->getJson($base)->assertOk()->json('data');
    expect(collect($data['allowed_transitions'])->firstWhere('value', 'preparado_envio')['available'])->toBeFalse();
    $this->postJson($base.'/transitions', ['status' => 'preparado_envio'])->assertUnprocessable()->assertJsonPath('code', 'process_not_ready');
    $this->getJson($base)->assertOk()->assertJsonPath('data.status', 'em_preparacao');
});
