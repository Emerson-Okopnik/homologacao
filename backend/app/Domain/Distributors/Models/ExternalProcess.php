<?php

namespace App\Domain\Distributors\Models;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonInterface|null $last_synced_at
 * @property array<string, mixed>|null $raw_metadata_json
 */
class ExternalProcess extends TenantEntity
{
    protected $table = 'external_processes';

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime', 'raw_metadata_json' => 'array'];
    }

    /** @var list<string> */
    protected array $auditExclude = ['raw_metadata_json'];

    /** @return BelongsTo<HomologationProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(HomologationProcess::class, 'homologation_process_id');
    }

    /** @return HasMany<ExternalSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(ExternalSubmission::class, 'external_process_id');
    }

    /** @return HasMany<ExternalPendingItem, $this> */
    public function pendingItems(): HasMany
    {
        return $this->hasMany(ExternalPendingItem::class, 'external_process_id');
    }

    /** @return HasMany<Inspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'external_process_id');
    }

    /** @return HasMany<ConnectionBudget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(ConnectionBudget::class, 'external_process_id');
    }
}
