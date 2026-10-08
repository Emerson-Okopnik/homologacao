<?php

namespace App\Domain\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Catálogo global (sem tenant_id): a chave é definida em código (PermissionKey).
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string $group
 */
class Permission extends Model
{
    protected $fillable = ['key', 'label', 'group'];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
