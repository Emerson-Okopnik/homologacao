<?php

namespace App\Http\Resources\Registry;

use App\Domain\Catalog\Models\EquipmentItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EquipmentItem
 */
final class EquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'power_w' => $this->power_w !== null ? (float) $this->power_w : null,
            'energy_kwh' => $this->energy_kwh !== null ? (float) $this->energy_kwh : null,
            'efficiency' => $this->efficiency !== null ? (float) $this->efficiency : null,
            'certification' => $this->certification,
            'active' => $this->active,
        ];
    }
}
