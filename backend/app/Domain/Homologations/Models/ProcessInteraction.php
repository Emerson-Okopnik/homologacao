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
 * Registro de contato/movimentação com a distribuidora (fluxo assistido).
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property string $description
 * @property string $channel
 * @property int|null $user_id
 * @property Carbon $occurred_at
 */
class ProcessInteraction extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    public const TYPES = ['nota', 'envio', 'resposta_distribuidora', 'contato'];

    public const CHANNELS = ['portal', 'email', 'telefone', 'presencial', 'api'];

    protected $fillable = ['homologation_process_id', 'type', 'description', 'channel', 'user_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
