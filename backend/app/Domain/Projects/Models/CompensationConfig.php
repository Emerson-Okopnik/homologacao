<?php

namespace App\Domain\Projects\Models;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\TenantEntity;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompensationConfig extends TenantEntity
{
    protected $table = 'compensation_config';

    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<CompensationUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(CompensationUnit::class, 'compensation_config_id');
    }

    public function totalPercentage(): float
    {
        return (float) $this->units->sum('percentage');
    }

    public function validateAllocation(): void
    {
        $units = $this->units;
        if ($units->isEmpty()) {
            throw new DomainException('Informe as UCs beneficiárias.', 'compensation_empty');
        }
        if ($this->allocation_rule === 'percentage' && ($units->contains(fn ($u) => $u->percentage === null) || abs($this->totalPercentage() - 100) > 0.001)) {
            throw new DomainException('O rateio deve totalizar 100%.', 'invalid_allocation');
        }
        if ($this->allocation_rule === 'priority' && ($units->contains(fn ($u) => ! $u->priority) || $units->pluck('priority')->unique()->count() !== $units->count())) {
            throw new DomainException('Informe prioridades únicas e positivas.', 'invalid_allocation');
        }
    }
}
