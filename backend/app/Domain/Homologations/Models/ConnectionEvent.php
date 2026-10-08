<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Homologations\Enums\ConnectionEventType;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property ConnectionEventType $type
 * @property Carbon $occurred_at
 * @property string|null $meter_number
 * @property string|null $notes
 */
class ConnectionEvent extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['homologation_process_id', 'type', 'occurred_at', 'meter_number', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['type' => ConnectionEventType::class, 'occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
