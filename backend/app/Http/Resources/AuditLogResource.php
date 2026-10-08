<?php

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event' => $this->event,
            'actor' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->uuid,
                'name' => $this->user->name,
            ] : null),
            'subject_type' => $this->auditable_type ? class_basename($this->auditable_type) : null,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'metadata' => $this->metadata,
            'justification' => $this->justification,
            'ip_address' => $this->ip_address,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
