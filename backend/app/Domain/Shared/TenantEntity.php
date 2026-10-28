<?php

namespace App\Domain\Shared;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
abstract class TenantEntity extends Model
{
    use Auditable, BelongsToTenant, HasPublicUuid;

    protected $guarded = ['id', 'uuid', 'tenant_id', 'created_at', 'updated_at'];

    protected static function boot(): void
    {
        parent::boot();
        static::created(fn (self $entity) => $entity->refresh());
    }
}
