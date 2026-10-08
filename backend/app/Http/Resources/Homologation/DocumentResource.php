<?php

namespace App\Http\Resources\Homologation;

use App\Domain\Documents\DocumentTypes;
use App\Domain\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'document_type' => $this->document_type,
            'type_label' => DocumentTypes::label($this->document_type),
            'owner' => DocumentTypes::owner($this->document_type),
            'version' => $this->version,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'review_status' => $this->review_status,
            'review_notes' => $this->review_notes,
            'uploaded_by' => $this->uploader?->name,
            'reviewed_by' => $this->reviewer?->name,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_current' => $this->whenLoaded('links', fn () => $this->links->contains('is_current', true)),
            'links' => $this->whenLoaded('links', fn () => $this->links->map(fn ($l) => [
                'type' => $l->linkable_type,
                'is_current' => $l->is_current,
                'label' => $this->linkLabel($l),
            ])->values()),
        ];
    }

    private function linkLabel($link): ?string
    {
        if (! $link->relationLoaded('linkable') || ! $link->linkable) {
            return null;
        }
        $m = $link->linkable;

        return match ($link->linkable_type) {
            'project' => "{$m->code} · ".($m->client?->name ?? ''),
            'equipment' => "{$m->manufacturer} {$m->model}",
            'process' => $m->code,
            'inspection' => "Vistoria #{$m->sequence}",
            default => null,
        };
    }
}
