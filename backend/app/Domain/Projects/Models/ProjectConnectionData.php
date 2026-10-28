<?php

namespace App\Domain\Projects\Models;

use App\Domain\Shared\TenantEntity;

class ProjectConnectionData extends TenantEntity
{
    protected $table = 'project_connection_data';

    protected function casts(): array
    {
        return ['supply_voltage' => 'decimal:2', 'installed_load_kw' => 'decimal:3', 'contracted_demand_kw' => 'decimal:3', 'existing_generation_kw' => 'decimal:3', 'emergency_generator' => 'boolean'];
    }
}
