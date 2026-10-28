<?php

namespace App\Domain\Homologations;

use App\Domain\Documents\DocumentRequirements;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Requirement;
use App\Domain\Homologations\Models\WorkflowStage;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

final class WorkflowDefinition
{
    /** @var array<int, bool> */
    private array $provisioned = [];

    /** @var array<int, Collection<int, WorkflowStage>> */
    private array $stages = [];

    public function provision(): void
    {
        $tenantId = app(TenantContext::class)->require()->id;
        if (isset($this->provisioned[$tenantId])) {
            return;
        }
        if (! WorkflowStage::query()->exists()) {
            foreach (ProcessStatus::cases() as $index => $status) {
                WorkflowStage::create([
                    'code' => $status->value, 'name' => $status->label(), 'order' => $index, 'stage_type' => $status->value,
                    'next_stage_rule_json' => ['next' => array_map(fn ($s) => $s->value, $status->allowedTransitions())], 'active' => true,
                ]);
            }
        }
        if (! Requirement::query()->exists()) {
            $base = ['formulario_solicitacao', 'art_trt', 'diagrama_unifilar', 'memorial_descritivo', 'datasheet_modulo', 'datasheet_inversor', 'certificado_inversor', 'documento_titular'];
            foreach ($base as $type) {
                $this->requirement($type, []);
            }
            $this->requirement('lista_rateio', ['modality' => ['autoconsumo_remoto', 'geracao_compartilhada', 'multiplas_uc']]);
            $this->requirement('datasheet_bateria', ['has_battery' => true]);
            $this->requirement('estudo_protecao', ['generation_type' => ['mini']]);
        }
        $this->provisioned[$tenantId] = true;
    }

    /** @param array<string, mixed> $conditions */
    private function requirement(string $type, array $conditions): void
    {
        Requirement::create(['code' => $type, 'name' => DocumentRequirements::label($type), 'required_document_type' => $type, 'applies_when_json' => $conditions, 'active' => true]);
    }

    public function stageFor(string $code): WorkflowStage
    {
        $stage = $this->activeStages()->firstWhere('code', $code);
        abort_unless($stage !== null, 404, 'Etapa não encontrada.');

        return $stage;
    }

    /** @return Collection<int, WorkflowStage> */
    public function transitions(HomologationProcess $process): Collection
    {
        $stage = $process->currentStage ?? $this->stageFor($process->status->value);

        return $this->activeStages()->whereIn('code', $stage->next_stage_rule_json['next'] ?? [])->values();
    }

    /** @return Collection<int, WorkflowStage> */
    private function activeStages(): Collection
    {
        $this->provision();
        $tenantId = app(TenantContext::class)->require()->id;

        return $this->stages[$tenantId] ??= WorkflowStage::where('active', true)->orderBy('order')->get();
    }

    public function invalidate(): void
    {
        unset($this->stages[app(TenantContext::class)->require()->id]);
    }
}
