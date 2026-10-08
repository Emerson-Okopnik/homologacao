<?php

namespace App\Domain\Projects\Models;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Shared\TenantEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $protection_config_json */
class ProjectInverter extends TenantEntity
{
    protected $table = 'project_inverters';

    protected function casts(): array
    {
        return ['nominal_ac_kw' => 'decimal:3', 'connection_voltage' => 'decimal:2', 'protection_config_json' => 'array'];
    }

    /** @return BelongsTo<EquipmentItem, $this> */
    public function inverter(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'inverter_model_id');
    }

    public function totalAcPower(): float
    {
        return ((float) $this->nominal_ac_kw) * $this->quantity;
    }
}
