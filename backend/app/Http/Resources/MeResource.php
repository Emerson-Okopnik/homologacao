<?php

namespace App\Http\Resources;

use App\Domain\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sessão atual: usuário, tenant e permissões efetivas (usadas pela SPA apenas para
 * esconder itens de UI; a autorização real é sempre feita no backend).
 *
 * @mixin User
 */
final class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = $this->tenant()->first();

        return [
            'user' => (new UserResource($this->resource->loadMissing('roles')))->toArray($request),
            'tenant' => $tenant ? ['id' => $tenant->uuid, 'name' => $tenant->name, 'slug' => $tenant->slug] : null,
            'permissions' => $this->permissionKeys()->all(),
        ];
    }
}
