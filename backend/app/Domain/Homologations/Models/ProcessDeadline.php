<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Homologations\Enums\DeadlineType;
use App\Domain\Rules\Models\RuleDecision;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read HomologationProcess $process
 * @property int $homologation_process_id
 * @property int $id
 * @property DeadlineType $deadline_type
 * @property string $status
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $due_at
 * @property int $days
 * @property string $day_count
 * @property int|null $rule_decision_id
 * @property CarbonInterface|null $closed_at
 */
class ProcessDeadline extends Model
{
    use BelongsToTenant;

    protected $fillable = ['homologation_process_id', 'deadline_type', 'status', 'starts_at', 'due_at', 'days', 'day_count', 'rule_decision_id'];

    protected function casts(): array
    {
        return [
            'deadline_type' => DeadlineType::class,
            'starts_at' => 'datetime',
            'due_at' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->status === 'OPEN' && $this->due_at->lt(today());
    }

    /** @return BelongsTo<RuleDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(RuleDecision::class, 'rule_decision_id');
    }

    /** @return BelongsTo<HomologationProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(HomologationProcess::class, 'homologation_process_id');
    }
}
