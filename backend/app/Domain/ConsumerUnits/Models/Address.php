<?php

namespace App\Domain\ConsumerUnits\Models;

use App\Domain\Shared\TenantEntity;

class Address extends TenantEntity
{
    protected $table = 'addresses';

    protected function casts(): array
    {
        return ['utm_x' => 'decimal:3', 'utm_y' => 'decimal:3'];
    }
}
