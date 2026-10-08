<?php

namespace App\Domain\Rules;

use App\Domain\Documents\DocumentTypes;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Projects\ProjectFactsBuilder;
use App\Domain\Rules\Enums\DecisionType;
use App\Domain\Rules\Enums\RequirementPhase;
use App\Domain\Rules\Models\RequirementRule;
use App\Domain\Rules\Models\RuleDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Avalia o checklist de uma fase. Só devolve requisitos aplicáveis, com motivo,
 * regra/versão e situação (atendido, pendente, dispensado).
 */
final class RequirementEngine
{
    public function __construct(
        private readonly ProjectFactsBuilder $factsBuilder,
        private readonly ConditionEvaluator $evaluator,
        private readonly RuleResolver $resolver,
    ) {}

    /**
     * @return array{phase: string, label: string, items: list<array<string, mixed>>, total: int, satisfied: int, percent: int, blocking: list<string>, facts: array<string, mixed>}
     */
    public function evaluate(SolarProject $project, RequirementPhase $phase, ?HomologationProcess $process = null): array
    {
        $process ??= $project->process;
        $facts = $this->factsBuilder->build($project, $process);
        $rules = $this->resolver->resolve(
            RequirementRule::class,
            Carbon::now(),
            $facts['distributor_code'],
            fn ($q) => $q->where('phase', $phase->value),
        );

        $documents = $this->approvedDocumentTypes($project, $process);
        $allDocuments = $this->currentDocuments($project, $process)->groupBy(fn ($d) => DocumentTypes::legacy($d->document_type));
        $waivers = $project->waivers()->whereNull('revoked_at')->get()->keyBy('requirement_code');

        $items = [];
        foreach ($rules as $rule) {
            $applies = $this->evaluator->evaluate($rule->conditions, $facts);
            if (! $applies['passed']) {
                continue;
            }

            $waiver = $waivers->get($rule->rule_code);

            if ($rule->kind === 'DOCUMENT') {
                $type = DocumentTypes::legacy($rule->document_type);
                $docs = $allDocuments->get($type, collect());
                $satisfied = $documents->contains($type);
                $detail = $satisfied ? null : ($docs->isNotEmpty()
                    ? 'Documento enviado, aguardando aprovação na revisão interna.'
                    : 'Documento não enviado.');
            } else {
                $check = $this->evaluator->evaluate($rule->satisfied_when, $facts);
                $satisfied = $check['passed'];
                $detail = $satisfied ? null : collect($check['failures'])->pluck('message')->filter()->implode(' ');
            }

            $status = match (true) {
                $satisfied => 'SATISFIED',
                $waiver !== null && $rule->waivable => 'WAIVED',
                default => 'PENDING',
            };

            $items[] = [
                'code' => $rule->rule_code,
                'version' => $rule->version,
                'label' => $rule->label,
                'kind' => $rule->kind,
                'document_type' => $rule->document_type,
                'document_label' => $rule->document_type ? DocumentTypes::label($rule->document_type) : null,
                'document_owner' => $rule->document_type ? DocumentTypes::owner($rule->document_type) : null,
                'outcome' => $rule->outcome,
                'reason' => $rule->reason,
                'source_reference' => $rule->source_reference,
                'waivable' => $rule->waivable,
                'waiver_reason' => $waiver?->reason,
                'status' => $status,
                'detail' => $detail ?: null,
            ];
        }

        $required = array_values(array_filter($items, fn ($i) => $i['outcome'] === 'REQUIRED'));
        $done = count(array_filter($required, fn ($i) => $i['status'] !== 'PENDING'));
        $blocking = array_values(array_map(
            fn ($i) => $i['label'].($i['detail'] ? " — {$i['detail']}" : ''),
            array_filter($required, fn ($i) => $i['status'] === 'PENDING'),
        ));

        return [
            'phase' => $phase->value,
            'label' => $phase->label(),
            'items' => $items,
            'total' => count($required),
            'satisfied' => $done,
            'percent' => count($required) === 0 ? 100 : (int) floor($done * 100 / count($required)),
            'blocking' => $blocking,
            'facts' => $facts,
        ];
    }

    /**
     * Avalia e grava o snapshot da decisão (usado em transições).
     *
     * @return array<string, mixed>
     */
    public function evaluateAndRecord(SolarProject $project, RequirementPhase $phase, ?HomologationProcess $process = null): array
    {
        $result = $this->evaluate($project, $phase, $process);

        $decision = RuleDecision::record(DecisionType::Requirements, $project, null, $result['facts'], [
            'phase' => $phase->value,
            'items' => $result['items'],
            'blocking' => $result['blocking'],
        ]);

        return [...$result, 'decision_id' => $decision->id];
    }

    /**
     * @return Collection<int, Document>
     */
    public function currentDocuments(SolarProject $project, ?HomologationProcess $process): Collection
    {
        $project->loadMissing('equipment');
        $owners = [['project', $project->id]];

        foreach ($project->equipment as $item) {
            $owners[] = ['equipment', $item->id];
        }

        if ($process) {
            $owners[] = ['process', $process->id];
            if ($execution = $process->execution) {
                $owners[] = ['execution', $execution->id];
            }
            foreach ($process->inspections as $inspection) {
                $owners[] = ['inspection', $inspection->id];
            }
            foreach ($process->connectionEvents as $event) {
                $owners[] = ['connection_event', $event->id];
            }
        }

        $documentIds = DocumentLink::query()
            ->where('is_current', true)
            ->where(function ($q) use ($owners): void {
                foreach ($owners as [$type, $id]) {
                    $q->orWhere(fn ($q) => $q->where('linkable_type', $type)->where('linkable_id', $id));
                }
            })
            ->pluck('document_id');

        return Document::query()->whereIn('id', $documentIds)->get();
    }

    /**
     * @return Collection<int, string>
     */
    private function approvedDocumentTypes(SolarProject $project, ?HomologationProcess $process): Collection
    {
        return $this->currentDocuments($project, $process)
            ->filter(fn ($d) => $d->isValid() && $d->verifyHash())
            ->map(fn ($d) => DocumentTypes::legacy($d->document_type))
            ->unique()
            ->values();
    }
}
