<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Models\User;

/**
 * A checagem de mesmo tenant é redundante com o TenantScope de propósito (defesa em profundidade).
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionKey::UsersView);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->sameTenant($actor, $target)
            && ($actor->is($target) || $actor->hasPermission(PermissionKey::UsersView));
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionKey::UsersManage);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->sameTenant($actor, $target) && $actor->hasPermission(PermissionKey::UsersManage);
    }

    private function sameTenant(User $actor, User $target): bool
    {
        return $actor->tenant_id === $target->tenant_id;
    }
}
