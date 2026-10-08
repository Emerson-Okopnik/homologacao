<?php

namespace App\Http\Resources\Homologation;

use App\Domain\Documents\DocumentRequirements;
use App\Domain\Documents\Models\ProcessDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProcessDocument
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
            'process_id' => $this->process?->uuid,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->uuid, 'code' => $this->project->code, 'client' => $this->project->client?->name] : null),
            'document_type' => $this->document_type,
            'type_label' => DocumentRequirements::label($this->document_type),
            'version' => $this->version,
            'is_current' => $this->is_current,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'issued_at' => $this->issued_at?->toDateString(), 'expires_at' => $this->expires_at?->toDateString(),
            'review_status' => $this->review_status,
            'review_notes' => $this->review_notes,
            'uploaded_by' => $this->uploader?->name,
            'reviewed_by' => $this->reviewer?->name,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'process' => $this->whenLoaded('process', fn () => $this->process ? [
                'id' => $this->process->uuid,
                'code' => $this->process->code,
                'client' => $this->process->relationLoaded('project') ? $this->process->project?->client?->name : null,
            ] : null),
        ];
    }
}
