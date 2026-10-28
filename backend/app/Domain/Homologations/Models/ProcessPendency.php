<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $homologation_process_id
 * @property string $origin
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property Carbon|null $due_date
 * @property string|null $resolution
 * @property int|null $created_by
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property int|null $external_pending_item_id
 */
class ProcessPendency extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['homologation_process_id', 'origin', 'title', 'description', 'status', 'due_date', 'resolution', 'created_by', 'external_pending_item_id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<HomologationProcess, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(HomologationProcess::class, 'homologation_process_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
