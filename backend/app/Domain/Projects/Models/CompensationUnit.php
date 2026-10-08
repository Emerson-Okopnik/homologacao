<?php

namespace App\Domain\Projects\Models;

use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $solar_project_id
 * @property int $consumer_unit_id
 * @property string|null $percentage
 * @property int|null $priority
 */
class CompensationUnit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['solar_project_id', 'consumer_unit_id', 'percentage', 'priority'];

    protected function casts(): array
    {
        return ['percentage' => 'decimal:2', 'priority' => 'integer'];
    }

    /** @return BelongsTo<ConsumerUnit, $this> */
    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }
}
