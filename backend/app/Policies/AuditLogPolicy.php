<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Models\User;

/**
 * Auditoria é somente leitura para todos, inclusive administradores.
 */
final class AuditLogPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionKey::AuditView);
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }
}
