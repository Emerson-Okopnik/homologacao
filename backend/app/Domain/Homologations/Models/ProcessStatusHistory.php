<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Histórico imutável de transições.
 *
 * @property int $id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $user_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class ProcessStatusHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['homologation_process_id', 'from_status', 'to_status', 'user_id', 'reason'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
