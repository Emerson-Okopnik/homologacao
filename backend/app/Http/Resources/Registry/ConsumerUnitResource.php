<?php

namespace App\Http\Resources\Registry;

use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConsumerUnit
 */
final class ConsumerUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->uuid, 'name' => $this->client->name]),
            'distributor' => $this->whenLoaded('distributor', fn () => DistributorResource::make($this->distributor)->toArray($request)),
            'address' => [
                'street' => $this->street,
                'number' => $this->address_number,
                'complement' => $this->complement,
                'district' => $this->district,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip,
                'utm_zone' => $this->address?->utm_zone, 'utm_x' => $this->address?->utm_x, 'utm_y' => $this->address?->utm_y,
            ],
            'full_address' => $this->fullAddress(),
            'voltage_class' => $this->voltage_class,
            'voltage' => $this->voltage !== null ? (float) $this->voltage : null, 'neutral_voltage' => $this->neutral_voltage !== null ? (float) $this->neutral_voltage : null,
            'supply_type' => $this->supply_type,
            'installed_load_kw' => $this->installed_load_kw !== null ? (float) $this->installed_load_kw : null,
            'contracted_demand_kw' => $this->contracted_demand_kw !== null ? (float) $this->contracted_demand_kw : null,
            'breaker_a' => $this->breaker_a,
            'active' => $this->active,
        ];
    }
}
