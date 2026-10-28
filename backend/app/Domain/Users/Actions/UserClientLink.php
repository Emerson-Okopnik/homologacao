<?php

namespace App\Domain\Users\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use Illuminate\Validation\ValidationException;

final class UserClientLink
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $roleSlugs
     * @return array<string, mixed>
     */
    public function normalize(array $data, array $roleSlugs, ?User $user = null): array
    {
        $permissions = Role::query()->whereIn('slug', $roleSlugs)->with('permissions')->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('key'))->unique();
        $portal = $permissions->contains(PermissionKey::PortalAccess->value);
        if (! $portal) {
            if (filled($data['client_id'] ?? null)) {
                throw ValidationException::withMessages(['client_id' => 'O vínculo com o titular exige o perfil de acesso ao portal do cliente.']);
            }

            return [...$data, 'client_id' => null];
        }
        if ($permissions->contains(fn ($key) => $key !== PermissionKey::PortalAccess->value)) {
            throw ValidationException::withMessages(['roles' => 'O acesso ao portal do cliente não pode ser combinado com permissões internas.']);
        }

        $client = array_key_exists('client_id', $data)
            ? Client::query()->where('uuid', $data['client_id'])->first()
            : $user?->client()->first();
        if (! $client) {
            throw ValidationException::withMessages(['client_id' => 'Selecione o cliente titular ao qual este acesso pertence.']);
        }

        return [...$data, 'client_id' => $client->id];
    }
}
