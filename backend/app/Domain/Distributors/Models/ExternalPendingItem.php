<?php

namespace App\Domain\Distributors\Models;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $resolved_at
 * @property-read ProcessDocument|null $responseDocument
 */
class ExternalPendingItem extends TenantEntity
{
    protected $table = 'external_pending_items';

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<ProcessDocument, $this> */
    public function responseDocument(): BelongsTo
    {
        return $this->belongsTo(ProcessDocument::class, 'response_document_id');
    }
}
