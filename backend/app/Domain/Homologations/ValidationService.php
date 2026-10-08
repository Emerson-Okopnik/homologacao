<?php

namespace App\Domain\Homologations;

use App\Domain\Documents\DocumentTypes;
use App\Domain\Homologations\Models\ChecklistItem;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Requirement;
use App\Domain\Projects\ProjectTechnicalData;
use App\Domain\Projects\ProjectVersionService;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Collection;

final class ValidationService
{
    /** @return Collection<int, ChecklistItem> */
    public function checklist(HomologationProcess $process): Collection
    {
        app(WorkflowDefinition::class)->provision();
        $documents = app(ProjectVersionService::class)->documents($process->project, $process)->keyBy(fn ($document) => DocumentTypes::legacy($document->document_type));
        foreach (Requirement::query()->get() as $requirement) {
            $applicable = $requirement->appliesTo($process->project) && (! $requirement->distributor_id || $requirement->distributor_id === $process->distributor_id);
            $item = ChecklistItem::firstOrCreate(['homologation_process_id' => $process->id, 'requirement_id' => $requirement->id]);
            $attributes = ['applicable' => $applicable];
            if ($requirement->required_document_type) {
                $document = $documents->get($requirement->required_document_type);
                $attributes += ['document_id' => $document?->id, 'status' => $document?->isValid() ? 'aprovado' : ($document?->review_status === 'reprovado' ? 'reprovado' : 'pendente'),
                    'validated_by' => $document?->reviewed_by, 'validated_at' => $document?->reviewed_at];
            } elseif ($item->validated_at && $item->validated_at->isBefore($process->project->updated_at)) {
                $attributes += ['status' => 'pendente', 'validated_at' => null, 'validated_by' => null];
            }
            $item->fill($attributes);
            if ($item->isDirty()) {
                $item->save();
            }
        }

        return $process->checklistItems()->with(['requirement', 'document.uploader', 'document.reviewer'])->get();
    }

    /** @return list<string> */
    public function issues(HomologationProcess $process, bool $verifyFiles = false): array
    {
        $project = $process->project;
        $issues = app(ProjectTechnicalData::class)->issues($project);
        $responsible = $project->technicalResponsible;
        if (! $responsible || ! $responsible->active || $responsible->registration_status !== 'regular') {
            $issues[] = 'Defina um responsável técnico ativo e regular.';
        }
        if ($responsible && ! $responsible->cpf) {
            $issues[] = 'Informe o CPF do responsável técnico.';
        }
        foreach ($this->checklist($process) as $item) {
            if (! $item->applicable) {
                continue;
            }
            if ($item->status !== 'aprovado') {
                $issues[] = 'Requisito não atendido: '.$item->requirement->name.'.';
            } elseif ($verifyFiles && $item->document && ! $item->document->verifyHash()) {
                $issues[] = 'Arquivo ausente ou divergente: '.$item->requirement->name.'.';
            }
        }
        if ($process->openPendencies()->whereNull('external_pending_item_id')->exists()) {
            $issues[] = 'Resolva as pendências internas em aberto.';
        }

        return array_values(array_unique($issues));
    }

    public function ensureReady(HomologationProcess $process): void
    {
        $issues = $this->issues($process, true);
        if ($issues) {
            throw new DomainException('Complete o dossiê: '.implode(' ', $issues), 'process_not_ready');
        }
        if ($process->process_type !== $process->project->generation_type) {
            $process->update(['process_type' => $process->project->generation_type]);
        }
    }
}
