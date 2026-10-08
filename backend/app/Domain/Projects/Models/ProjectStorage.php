<?php

namespace App\Domain\Projects\Models;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Shared\TenantEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStorage extends TenantEntity
{
    protected $table = 'project_storage';

    protected function casts(): array
    {
        return ['energy_kwh' => 'decimal:3', 'power_kw' => 'decimal:3', 'dispatchable' => 'boolean'];
    }

    /** @return BelongsTo<EquipmentItem, $this> */
    public function battery(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'battery_model_id');
    }
}
