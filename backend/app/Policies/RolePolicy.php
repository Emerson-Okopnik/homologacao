<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Models\User;

final class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionKey::RolesView)
            || $actor->hasPermission(PermissionKey::UsersManage);
    }
}
