<?php

namespace App\Domain\Users\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name?: string, email?: string, password?: string, active?: bool}  $data
     * @param  list<string>|null  $roleSlugs  null = não alterar perfis
     */
    public function handle(User $actor, User $user, array $data, ?array $roleSlugs): User
    {
        return DB::transaction(function () use ($actor, $user, $data, $roleSlugs): User {
            $user->roles()->lockForUpdate()->get();

            if ($actor->is($user) && array_key_exists('active', $data) && $data['active'] === false) {
                throw new DomainException('Você não pode desativar o próprio usuário.', 'cannot_deactivate_self');
            }

            $willBeAdmin = $roleSlugs === null
                ? $user->roles->contains('slug', SystemRole::Administrator->value)
                : in_array(SystemRole::Administrator->value, $roleSlugs, true);
            $willBeActive = $data['active'] ?? $user->active;

            if ($this->isActiveAdmin($user) && (! $willBeAdmin || ! $willBeActive) && $this->activeAdminCount() <= 1) {
                throw new DomainException('O tenant precisa manter ao menos um administrador ativo.', 'last_admin');
            }

            $user->fill($data)->save();

            if ($roleSlugs !== null) {
                $before = $user->roles->pluck('slug')->sort()->values()->all();
                $user->roles()->sync(Role::query()->whereIn('slug', $roleSlugs)->pluck('id'));
                $after = $user->roles()->pluck('slug')->sort()->values()->all();

                if ($before !== $after) {
                    $this->audit->log('user.roles_changed', $user, ['roles' => $before], ['roles' => $after]);
                }
            }

            $user->flushPermissionCache();

            return $user->load('roles');
        });
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->active && $user->roles->contains('slug', SystemRole::Administrator->value);
    }

    private function activeAdminCount(): int
    {
        return User::query()
            ->where('active', true)
            ->whereHas('roles', fn ($q) => $q->where('slug', SystemRole::Administrator->value))
            ->count();
    }
}
