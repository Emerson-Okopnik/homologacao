<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Shared\TenantEntity;
use App\Domain\Users\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface $entered_at
 * @property CarbonInterface|null $left_at
 * @property-read User|null $actor
 */
class ProcessStageHistory extends TenantEntity
{
    protected $table = 'process_stage_history';

    protected function casts(): array
    {
        return ['entered_at' => 'datetime', 'left_at' => 'datetime'];
    }

    /** @return BelongsTo<WorkflowStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
