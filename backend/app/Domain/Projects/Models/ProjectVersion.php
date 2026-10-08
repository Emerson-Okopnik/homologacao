<?php

namespace App\Domain\Projects\Models;

use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Snapshot congelado do projeto no envio/reenvio. Imutável.
 *
 * @property int $id
 * @property string $uuid
 * @property int $version
 * @property string $reason
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_sha256
 * @property \Illuminate\Support\Carbon $created_at
 */
class ProjectVersion extends Model
{
    use BelongsToTenant;
    use HasPublicUuid;

    public const UPDATED_AT = null;

    protected $fillable = ['solar_project_id', 'version', 'reason', 'snapshot', 'snapshot_sha256', 'created_by'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Versões congeladas do projeto não podem ser alteradas.'));
        static::deleting(fn () => throw new LogicException('Versões congeladas do projeto não podem ser excluídas.'));
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
