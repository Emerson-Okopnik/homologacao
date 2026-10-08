<?php

namespace App\Domain\Distributors\Models;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface $issued_at
 * @property CarbonInterface|null $expires_at
 */
class ConnectionBudget extends TenantEntity
{
    protected $table = 'connection_budgets';

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'expires_at' => 'date', 'amount' => 'decimal:2', 'works_required' => 'boolean'];
    }

    /** @return BelongsTo<ProcessDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ProcessDocument::class, 'document_id');
    }
}
