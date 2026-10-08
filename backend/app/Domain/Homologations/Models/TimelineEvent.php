<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $type
 * @property string $title
 * @property string|null $description
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 */
class TimelineEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['homologation_process_id', 'type', 'title', 'description', 'payload', 'user_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos da linha do tempo são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Eventos da linha do tempo são imutáveis.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
