<?php

namespace App\Domain\Users\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, password: string, active?: bool}  $data
     * @param  list<string>  $roleSlugs
     */
    public function handle(array $data, array $roleSlugs): User
    {
        return DB::transaction(function () use ($data, $roleSlugs): User {
            $user = User::query()->create($data);

            // Role é tenant-scoped: slugs de outro tenant simplesmente não são encontrados.
            $roleIds = Role::query()->whereIn('slug', $roleSlugs)->pluck('id');
            $user->roles()->sync($roleIds);

            $this->audit->log('user.roles_changed', $user, null, ['roles' => $roleSlugs]);

            return $user->load('roles');
        });
    }
}
