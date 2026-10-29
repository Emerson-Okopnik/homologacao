<?php

namespace App\Domain\Users;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PasswordResetCredentials
{
    /** @return array<string, mixed> */
    public static function forEmail(string $email): array
    {
        return [
            'email' => $email,
            'active' => true,
            'eligible_tenant' => fn (Builder $query) => $query->whereHas('tenant', fn (Builder $tenant) => $tenant->where('active', true)),
        ];
    }

    public static function eligible(User $user): bool
    {
        return $user->active && $user->tenant()->where('active', true)->exists();
    }
}
