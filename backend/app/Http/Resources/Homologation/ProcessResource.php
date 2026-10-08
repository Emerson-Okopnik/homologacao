<?php

namespace App\Http\Resources\Homologation;

use App\Domain\Documents\DocumentRequirements;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Http\Resources\Registry\DistributorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'allowed_transitions' => array_map(
                fn ($s) => ['value' => $s->value, 'label' => $s->label()],
                $this->status->allowedTransitions(),
            ),
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
        $required = DocumentRequirements::requiredFor($this->project);
        $current = $this->currentDocuments->keyBy('document_type');
        $types = array_values(array_unique([...$required, ...$current->keys()->all()]));

        return array_map(fn (string $type) => [
            'type' => $type,
            'label' => DocumentRequirements::label($type),
            'required' => in_array($type, $required, true),
            'document' => $current->has($type) ? DocumentResource::make($current->get($type))->toArray($request) : null,
        ], $types);
    }
}
