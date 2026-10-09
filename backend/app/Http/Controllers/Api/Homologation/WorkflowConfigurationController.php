<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Distributors\Models\Distributor;
use App\Domain\Distributors\Models\ExternalCredential;
use App\Domain\Documents\DocumentRequirements;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Enums\WorkflowStage as ProcessStage;
use App\Domain\Homologations\Models\ChecklistItem;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Requirement;
use App\Domain\Homologations\Models\WorkflowStage;
use App\Domain\Homologations\WorkflowDefinition;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class WorkflowConfigurationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('projects.view');
        app(WorkflowDefinition::class)->provision();

        return response()->json(['data' => [
            'phases' => array_map(fn (ProcessStage $phase) => ['value' => $phase->value, 'label' => $phase->label()], ProcessStage::ordered()),
            'status_types' => array_map(fn (ProcessStatus $status) => ['value' => $status->value, 'label' => $status->label(), 'phase' => $status->workflowStage()?->value, 'terminal' => $status->isTerminal()], ProcessStatus::cases()),
            'stages' => WorkflowStage::orderBy('order')->get()->map(fn ($s) => $this->stage($s)),
            'requirements' => Requirement::with('distributor')->orderBy('id')->get()->map(fn ($r) => ['id' => $r->uuid, 'code' => $r->code, 'name' => $r->name, 'distributor_id' => $r->distributor?->uuid, 'conditions' => $r->applies_when_json ?? [], 'required_document_type' => $r->required_document_type, 'active' => $r->active]),
            'credentials' => ExternalCredential::get()->map(fn ($c) => ['id' => $c->uuid, 'distributor_id' => Distributor::findOrFail($c->distributor_id)->uuid, 'credential_ref' => $c->credential_ref, 'auth_type' => $c->auth_type, 'expires_at' => $c->expires_at?->toIso8601String(), 'active' => $c->active])]]);
    }

    public function saveStage(Request $request, ?WorkflowStage $stage = null): JsonResponse
    {
        $this->authorize('workflow.configure');
        app(WorkflowDefinition::class)->provision();
        $data = $request->validate(['code' => ['required', 'regex:/^[a-z][a-z0-9_]{0,59}$/'], 'name' => ['required', 'string', 'max:120'], 'order' => ['required', 'integer', 'min:0', 'max:1000'],
            'stage_type' => ['required', Rule::enum(ProcessStatus::class)], 'next' => ['present', 'array', 'max:50'], 'next.*' => ['required', 'string', 'distinct'], 'active' => ['required', 'boolean']]);
        if ($data['code'] === 'rascunho' && (! $data['active'] || $data['stage_type'] !== 'rascunho')) {
            throw new DomainException('A etapa inicial deve permanecer ativa como rascunho para abrir novos processos.', 'initial_stage_required');
        }
        if ($stage && $data['code'] !== $stage->code) {
            throw new DomainException('O código de uma etapa existente é permanente.', 'stage_code_immutable');
        }
        if ($stage && (! $data['active'] || $data['stage_type'] !== $stage->stage_type) && HomologationProcess::where('current_stage_id', $stage->id)->exists()) {
            throw new DomainException('Mova os processos desta etapa antes de desativá-la ou alterar sua natureza.', 'stage_in_use', 409);
        }
        if (WorkflowStage::where('code', $data['code'])->when($stage, fn ($q) => $q->whereKeyNot($stage->id))->exists()) {
            throw new DomainException('Este código de etapa já existe.', 'stage_duplicated', 409);
        }
        foreach ($data['next'] as $code) {
            if ($code !== $data['code'] && ! WorkflowStage::where('code', $code)->where('active', true)->exists()) {
                throw new DomainException('Selecione etapas seguintes ativas.', 'stage_target_invalid');
            }
        }
        if (in_array($data['stage_type'], ['conectado', 'cancelado'], true) && $data['next']) {
            throw new DomainException('Etapas encerradas não podem avançar.', 'terminal_stage');
        }
        $attributes = [...array_diff_key($data, ['next' => true]), 'next_stage_rule_json' => ['next' => $data['next']]];
        $stage ? $stage->update($attributes) : WorkflowStage::create($attributes);
        app(WorkflowDefinition::class)->invalidate();

        return $this->index();
    }

    public function saveRequirement(Request $request, ?Requirement $requirement = null): JsonResponse
    {
        $this->authorize('requirements.configure');
        app(WorkflowDefinition::class)->provision();
        $data = $request->validate(['code' => ['required', 'regex:/^[a-z][a-z0-9_]{0,79}$/'], 'name' => ['required', 'string', 'max:255'], 'distributor_id' => ['nullable', 'uuid'],
            'required_document_type' => ['nullable', Rule::in(array_keys(DocumentRequirements::TYPES))], 'active' => ['required', 'boolean'],
            'conditions' => ['present', 'array:min_power_kw,max_power_kw,modality,generation_type,installation_type,has_battery'],
            'conditions.min_power_kw' => ['nullable', 'numeric', 'min:0'], 'conditions.max_power_kw' => ['nullable', 'numeric', 'min:0'],
            'conditions.modality' => ['array'], 'conditions.modality.*' => [Rule::in(array_keys(SolarProject::MODALITIES))],
            'conditions.generation_type' => ['array'], 'conditions.generation_type.*' => [Rule::in(['micro', 'mini'])],
            'conditions.installation_type' => ['array'], 'conditions.installation_type.*' => [Rule::in(['rooftop', 'ground', 'other'])], 'conditions.has_battery' => ['boolean']]);
        if (isset($data['conditions']['min_power_kw'], $data['conditions']['max_power_kw']) && $data['conditions']['max_power_kw'] < $data['conditions']['min_power_kw']) {
            throw new DomainException('A potência máxima deve ser maior que a mínima.', 'requirement_range_invalid');
        }
        if (Requirement::where('code', $data['code'])->when($requirement, fn ($q) => $q->whereKeyNot($requirement->id))->exists()) {
            throw new DomainException('Este código de requisito já existe.', 'requirement_duplicated', 409);
        }
        $data['distributor_id'] = ! empty($data['distributor_id']) ? Distributor::where('uuid', $data['distributor_id'])->firstOrFail()->id : null;
        $data['applies_when_json'] = $data['conditions'];
        unset($data['conditions']);
        $requirement ? $requirement->update($data) : Requirement::create($data);

        return $this->index();
    }

    public function credential(Request $request): JsonResponse
    {
        $this->authorize('integrations.configure');
        $data = $request->validate(['distributor_id' => ['required', 'uuid'], 'credential_ref' => ['required', 'string', 'max:120'], 'auth_type' => ['required', Rule::in(['portal', 'oauth2', 'api_key', 'certificate'])], 'expires_at' => ['nullable', 'date'], 'active' => ['required', 'boolean']]);
        $data['distributor_id'] = Distributor::where('uuid', $data['distributor_id'])->firstOrFail()->id;
        ExternalCredential::updateOrCreate(['distributor_id' => $data['distributor_id'], 'credential_ref' => $data['credential_ref']], $data);

        return $this->index();
    }

    public function reviewChecklist(Request $request, ChecklistItem $item): JsonResponse
    {
        $this->authorize('homologations.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['aprovado', 'reprovado'])], 'notes' => ['required', 'string', 'min:3', 'max:2000']]);
        $requirement = $item->requirement;
        if ($requirement->required_document_type) {
            throw new DomainException('Revise o documento vinculado a este requisito.', 'document_review_required');
        }
        if (! $item->applicable) {
            throw new DomainException('Este requisito não se aplica ao processo.', 'checklist_not_applicable');
        }
        $data['status'] === 'aprovado' ? $item->approve($request->user()->id, $data['notes']) : $item->reject($request->user()->id, $data['notes']);

        return response()->json(['message' => 'Requisito revisado.']);
    }

    /** @return array<string, mixed> */
    private function stage(WorkflowStage $s): array
    {
        $status = ProcessStatus::from($s->stage_type);

        return ['id' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'order' => $s->order, 'stage_type' => $s->stage_type,
            'phase' => $status->workflowStage()?->value, 'terminal' => $status->isTerminal(),
            'next' => $s->next_stage_rule_json['next'] ?? [], 'active' => $s->active];
    }
}
