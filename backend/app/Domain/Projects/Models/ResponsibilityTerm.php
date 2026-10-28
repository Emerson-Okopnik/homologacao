<?php

namespace App\Domain\Projects\Models;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Shared\TenantEntity;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $solar_project_id
 * @property CarbonInterface $issued_at
 * @property CarbonInterface|null $valid_until
 */
class ResponsibilityTerm extends TenantEntity
{
    protected $table = 'responsibility_terms';

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'valid_until' => 'date'];
    }

    /** @return BelongsTo<ProcessDocument, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ProcessDocument::class, 'file_id');
    }

    /** @return BelongsTo<TechnicalResponsible, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(TechnicalResponsible::class, 'technical_responsible_id');
    }
}
