<?php

namespace App\Domain\Clients\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $type
 * @property string $document
 * @property string $name
 * @property string|null $trade_name
 * @property string|null $email
 * @property string|null $phone
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Client extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;
    use SoftDeletes;

    protected $fillable = ['type', 'document', 'name', 'trade_name', 'email', 'phone', 'status', 'notes'];

    /**
     * @return HasMany<ClientContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    /**
     * @return HasMany<ConsumerUnit, $this>
     */
    public function consumerUnits(): HasMany
    {
        return $this->hasMany(ConsumerUnit::class);
    }

    /**
     * @return HasMany<SolarProject, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(SolarProject::class);
    }
}
