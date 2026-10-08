<?php

namespace App\Domain\Distributors\Models;

use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;

/** @property CarbonInterface|null $expires_at */
class ExternalCredential extends TenantEntity
{
    protected $table = 'external_credentials';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'active' => 'boolean'];
    }
}
