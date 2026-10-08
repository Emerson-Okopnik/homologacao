<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Projects\Enums\ResponsibilityPurpose;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $solar_project_id
 * @property int $technical_responsible_id
 * @property ResponsibilityPurpose $purpose
 * @property string|null $art_number
 */
class TechnicalResponsibility extends Model
{
    use Auditable;
    use BelongsToTenant;

    protected $fillable = ['solar_project_id', 'technical_responsible_id', 'purpose', 'art_number', 'created_by'];

    protected function casts(): array
    {
        return ['purpose' => ResponsibilityPurpose::class];
    }

    /** @return BelongsTo<TechnicalResponsible, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(TechnicalResponsible::class, 'technical_responsible_id');
    }
}
