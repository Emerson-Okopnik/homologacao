<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Distributors\Models\Distributor;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\TenantEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $applies_when_json */
class Requirement extends TenantEntity
{
    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    protected $table = 'requirements';

    protected function casts(): array
    {
        return ['applies_when_json' => 'array', 'active' => 'boolean'];
    }

    public function appliesTo(SolarProject $project): bool
    {
        foreach ($this->applies_when_json ?? [] as $key => $expected) {
            if ($key === 'min_power_kw' && $project->accessPowerKw() < (float) $expected) {
                return false;
            }
            if ($key === 'max_power_kw' && $project->accessPowerKw() > (float) $expected) {
                return false;
            }
            if (in_array($key, ['modality', 'generation_type', 'installation_type'], true) && ! in_array($project->getAttribute($key), (array) $expected, true)) {
                return false;
            }
            if ($key === 'has_battery' && $project->has_battery !== (bool) $expected) {
                return false;
            }
        }

        return $this->active;
    }
}
