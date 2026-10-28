<?php

namespace App\Domain\Projects\Models;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Shared\TenantEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolarArray extends TenantEntity
{
    protected $table = 'project_arrays';

    protected function casts(): array
    {
        return ['azimuth' => 'decimal:2', 'tilt' => 'decimal:2'];
    }

    /** @return BelongsTo<EquipmentItem, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'module_model_id');
    }

    public function dcPower(): float
    {
        return ((float) $this->module->power_w) * $this->module_quantity / 1000;
    }
}
