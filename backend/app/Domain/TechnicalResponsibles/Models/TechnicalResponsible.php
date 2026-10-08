<?php

namespace App\Domain\TechnicalResponsibles\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $council
 * @property string $registration
 * @property string $state
 * @property string|null $email
 * @property string|null $phone
 * @property string $registration_status
 * @property bool $active
 */
class TechnicalResponsible extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['name', 'council', 'registration', 'state', 'email', 'phone', 'registration_status', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return HasMany<SolarProject, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(SolarProject::class);
    }
}
