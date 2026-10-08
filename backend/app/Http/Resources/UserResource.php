<?php

namespace App\Http\Resources;

use App\Domain\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * IDs numéricos e tenant_id nunca saem da API; o identificador público é o UUID.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'active' => $this->active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'is_super_admin' => $this->when(
                (bool) $request->user()?->is_super_admin,
                fn () => $this->isSuperAdmin(),
            ),
        ];
    }
}
