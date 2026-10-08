<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Shared\TenantEntity;
use App\Domain\Users\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface|null $assigned_at
 * @property CarbonInterface|null $revoked_at
 */
class ProcessAssignment extends TenantEntity
{
    protected $table = 'process_assignments';

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'revoked_at' => 'datetime', 'active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
