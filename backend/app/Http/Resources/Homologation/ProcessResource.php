<?php

namespace App\Http\Resources\Homologation;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Http\Resources\Registry\DistributorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HomologationProcess
 */
final class ProcessResource extends JsonResource
{
    /** @var array<string, mixed>|null */
    private ?array $extra = null;

    /**
     * @param  array<string, mixed>  $extra  ações disponíveis, checklist etc. calculados pelo domínio
     */
    public function with_(array $extra): self
    {
        $this->extra = $extra;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $openDeadline = $this->relationLoaded('deadlines') ? $this->deadlines->firstWhere('status', 'OPEN') : null;

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            'network_work_status' => $this->network_work_status->value,
            'network_work_label' => $this->network_work_status->label(),
            'protocol_number' => $this->protocol_number,
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'open_deadline' => $openDeadline ? $this->deadline($openDeadline) : null,
            'distributor' => $this->whenLoaded('distributor', fn () => DistributorResource::make($this->distributor)->toArray($request)),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->uuid, 'name' => $this->assignee->name] : null),
            'project' => $this->whenLoaded('project', fn () => ProjectResource::make($this->project)->toArray($request)),
            'open_pendencies_count' => $this->whenCounted('openPendencies'),
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
            'pendencies' => $this->whenLoaded('pendencies', fn () => PendencyResource::collection($this->pendencies)->toArray($request)),
            'interactions' => $this->whenLoaded('interactions', fn () => $this->interactions->map(fn ($i) => [
                'id' => $i->uuid,
                'type' => $i->type,
                'channel' => $i->channel,
                'description' => $i->description,
                'user' => $i->user?->name,
                'occurred_at' => $i->occurred_at->toIso8601String(),
            ])->values()),
            ...($this->extra ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deadline($d): array
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
