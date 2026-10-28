<?php

namespace App\Domain\Distributors\Models;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $scheduled_at
 * @property CarbonInterface|null $performed_at
 * @property CarbonInterface|null $connection_approved_at
 * @property-read ProcessDocument|null $report
 */
class Inspection extends TenantEntity
{
    protected $table = 'inspections';

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'scheduled_at' => 'datetime', 'performed_at' => 'datetime', 'connection_approved_at' => 'datetime'];
    }

    /** @return BelongsTo<ProcessDocument, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ProcessDocument::class, 'report_document_id');
    }

    public function isConnectionEvidence(): bool
    {
        return $this->status === 'aprovada' && $this->connection_approved_at !== null && $this->report?->isValid() && $this->report->verifyHash();
    }
}
