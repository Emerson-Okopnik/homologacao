<?php

namespace App\Domain\Users\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Enums\PermissionKey;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $active
 * @property Carbon|null $last_login_at
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'active'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string> */
    protected array $auditExclude = ['password', 'remember_token', 'last_login_at'];

    /** @var Collection<int, string>|null */
    private ?Collection $permissionCache = null;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * @return Collection<int, string>
     */
    public function permissionKeys(): Collection
    {
        return $this->permissionCache ??= Permission::query()
            ->whereHas('roles', fn ($q) => $q
                ->where('roles.tenant_id', $this->tenant_id)
                ->whereHas('users', fn ($u) => $u->whereKey($this->getKey())))
            ->pluck('key')
            ->unique()
            ->values();
    }

    public function hasPermission(PermissionKey|string $permission): bool
    {
        $key = $permission instanceof PermissionKey ? $permission->value : $permission;

        return $this->permissionKeys()->contains($key);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }
}
