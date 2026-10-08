<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Homologations\Enums\DeadlineType;
use App\Domain\Rules\Models\RuleDecision;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property DeadlineType $deadline_type
 * @property string $status
 * @property Carbon $starts_at
 * @property Carbon $due_at
 * @property int $days
 * @property string $day_count
 * @property int|null $rule_decision_id
 * @property Carbon|null $closed_at
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
}
