<?php

namespace App\Http\Resources\Homologation;

use App\Domain\Homologations\Models\ProcessPendency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProcessPendency
 */
final class PendencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'origin' => $this->origin,
            'external' => $this->external_pending_item_id !== null,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'due_date' => $this->due_date?->toDateString(),
            'resolution' => $this->resolution,
            'created_by' => $this->author?->name,
            'resolved_by' => $this->resolver?->name,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
