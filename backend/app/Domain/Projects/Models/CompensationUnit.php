<?php

namespace App\Domain\Projects\Models;

use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Shared\TenantEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensationUnit extends TenantEntity
{
    protected $table = 'compensation_units';

    protected function casts(): array
    {
        return ['percentage' => 'decimal:3'];
    }

    /** @return BelongsTo<ConsumerUnit, $this> */
    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }
}
