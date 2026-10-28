<?php

namespace App\Http\Resources\Registry;

use App\Domain\Clients\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
final class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'document' => $this->document,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'notes' => $this->notes,
            'consumer_units_count' => $this->whenCounted('consumerUnits'),
            'projects_count' => $this->whenCounted('projects'),
            'contacts' => ClientContactResource::collection($this->whenLoaded('contacts')),
            'consumer_units' => ConsumerUnitResource::collection($this->whenLoaded('consumerUnits')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
