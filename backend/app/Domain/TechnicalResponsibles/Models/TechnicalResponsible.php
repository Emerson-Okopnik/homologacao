<?php

namespace App\Domain\TechnicalResponsibles\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int|null $user_id
 * @property-read User|null $user
 */
class TechnicalResponsible extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['user_id', 'name', 'cpf', 'council', 'registration', 'state', 'email', 'phone', 'registration_status', 'active'];

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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
