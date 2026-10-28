<?php

namespace App\Domain\Distributors\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Distributors\Enums\IntegrationMode;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $state
 * @property IntegrationMode $integration_mode
 * @property string|null $secret_ref
 * @property string|null $portal_url
 * @property bool $active
 */
class Distributor extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['code', 'name', 'state', 'integration_mode', 'secret_ref', 'portal_url', 'active'];

    protected function casts(): array
    {
        return [
            'integration_mode' => IntegrationMode::class,
            'active' => 'boolean',
        ];
    }
}
