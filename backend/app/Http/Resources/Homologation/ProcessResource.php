<?php

namespace App\Http\Resources\Homologation;

use App\Domain\Documents\DocumentRequirements;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\ProcessDeadline;
use App\Domain\Homologations\Models\WorkflowStage;
use App\Domain\Homologations\ValidationService;
use App\Domain\Homologations\WorkflowDefinition;
use App\Domain\Projects\ProjectVersionService;
use App\Http\Resources\Registry\DistributorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin HomologationProcess
 */
final class ProcessResource extends JsonResource
{
    /** @var list<string>|null */
    private ?array $readinessIssues = null;

    /**
     * @param  list<string>  $issues
     */
    public function withReadiness(array $issues): self
    {
        $this->readinessIssues = $issues;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkflowStage|null $stage */
        $stage = $this->resource->getRelationValue('currentStage');

        $openDeadline = $this->relationLoaded('deadlines') ? $this->deadlines->firstWhere('status', 'OPEN') : null;

        return [
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            'network_work_status' => $this->network_work_status->value,
            'network_work_label' => $this->network_work_status->label(),
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'open_deadline' => $openDeadline ? $this->deadline($openDeadline) : null,
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion ? [
                'version' => $this->currentVersion->version,
                'reason' => $this->currentVersion->reason,
                'sha256' => $this->currentVersion->snapshot_sha256,
                'created_at' => $this->currentVersion->created_at->toIso8601String(),
            ] : null),
            'deadlines' => $this->whenLoaded('deadlines', fn () => $this->deadlines->map(fn ($d) => $this->deadline($d))->values()),
            'execution' => $this->whenLoaded('execution', fn () => $this->execution ? [
                'id' => $this->execution->uuid,
                'started_at' => $this->execution->started_at?->toDateString(),
                'completed_at' => $this->execution->completed_at->toDateString(),
                'notes' => $this->execution->notes,
            ] : null),
            'inspections' => $this->whenLoaded('inspections', fn () => $this->inspections->map(fn ($i) => [
                'id' => $i->uuid,
                'sequence' => $i->sequence,
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'requested_at' => $i->requested_at->toIso8601String(),
                'scheduled_for' => $i->scheduled_for?->toDateString(),
                'result_at' => $i->result_at?->toIso8601String(),
                'result_notes' => $i->result_notes,
                'is_open' => $i->status->isOpen(),
            ])->values()),
            'connection_events' => $this->whenLoaded('connectionEvents', fn () => $this->connectionEvents->map(fn ($e) => [
                'id' => $e->uuid,
                'type' => $e->type->value,
                'type_label' => $e->type->label(),
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'meter_number' => $e->meter_number,
                'notes' => $e->notes,
            ])->values()),
            'timeline' => $this->whenLoaded('timeline', fn () => $this->timeline->map(fn ($t) => [
                'type' => $t->type,
                'title' => $t->title,
                'description' => $t->description,
                'user' => $t->user?->name,
                'occurred_at' => $t->occurred_at->toIso8601String(),
            ])->values()),
            'id' => $this->uuid,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $stage !== null ? $stage->name : $this->status->label(),
            'stage_code' => $stage !== null ? $stage->code : $this->status->value,
            'priority' => $this->priority,
            'process_type' => $this->process_type,
            'allowed_transitions' => app(WorkflowDefinition::class)->transitions($this->resource)->map(fn ($s) => ['value' => $s->code, 'label' => $s->name, 'stage_type' => $s->stage_type])->values(),
            'editable' => $this->status->isEditable(),
            'protocol_number' => $this->protocol_number,
            'due_date' => $this->due_date?->toDateString(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'connected_at' => $this->connected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'distributor' => $this->whenLoaded('distributor', fn () => DistributorResource::make($this->distributor)->toArray($request)),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->uuid, 'name' => $this->assignee->name] : null),
            'project' => $this->whenLoaded('project', fn () => ProjectResource::make($this->project)->toArray($request)),
            'open_pendencies_count' => $this->whenCounted('openPendencies'),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($h) => [
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'reason' => $h->reason,
                'user' => $h->user?->name,
                'created_at' => $h->created_at->toIso8601String(),
            ])->values()),
            'pendencies' => $this->whenLoaded('pendencies', fn () => PendencyResource::collection($this->pendencies)->toArray($request)),
            'interactions' => $this->whenLoaded('interactions', fn () => $this->interactions->map(fn ($i) => [
                'id' => $i->uuid,
                'type' => $i->type,
                'channel' => $i->channel,
                'description' => $i->description,
                'user' => $i->user?->name,
                'occurred_at' => $i->occurred_at->toIso8601String(),
            ])->values()),
            'checklist' => $this->when(
                $this->relationLoaded('currentDocuments') && $this->relationLoaded('project'),
                fn () => $this->checklist($request),
            ),
            'readiness_issues' => $this->when($this->readinessIssues !== null, fn () => $this->readinessIssues),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function checklist(Request $request): array
    {
        $items = app(ValidationService::class)->checklist($this->resource);
        /** @var Collection<int, array<string, mixed>> $result */
        $result = $items->filter(fn ($i) => $i->applicable)->map(fn ($i) => ['id' => $i->uuid, 'type' => $i->requirement->required_document_type, 'label' => $i->requirement->name,
            'required' => true, 'status' => $i->status, 'notes' => $i->notes, 'document' => $i->document ? DocumentResource::make($i->document)->toArray($request) : null])->values();
        $current = app(ProjectVersionService::class)->documents($this->project, $this->resource);
        foreach ($current as $doc) {
            if (! $result->contains(fn ($i) => $i['type'] === $doc->document_type)) {
                $result->push(['type' => $doc->document_type, 'label' => DocumentRequirements::label($doc->document_type), 'required' => false, 'document' => DocumentResource::make($doc)->toArray($request)]);
            }
        }

        return $result->all();
    }

    /** @return array<string, mixed> */
    private function deadline(ProcessDeadline $d): array
    {
        return [
            'type' => $d->deadline_type->value,
            'label' => $d->deadline_type->label(),
            'status' => $d->status,
            'starts_at' => $d->starts_at->toIso8601String(),
            'due_at' => $d->due_at->toDateString(),
            'days' => $d->days,
            'day_count' => $d->day_count,
            'overdue' => $d->status === 'OPEN' && $d->due_at->lt(today()),
        ];
    }
}
