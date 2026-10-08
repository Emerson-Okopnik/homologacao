<?php

namespace App\Domain\Clients\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $client_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $role
 * @property bool $is_legal_representative
 */
class ClientContact extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['name', 'email', 'phone', 'role', 'is_legal_representative'];

    protected function casts(): array
    {
        return ['is_legal_representative' => 'boolean'];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
