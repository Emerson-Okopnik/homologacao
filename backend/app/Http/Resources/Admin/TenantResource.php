<?php

namespace App\Http\Resources\Admin;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'active' => $this->active,
            'users_count' => $this->whenCounted('users'),
            'roles_count' => $this->whenCounted('roles'),
            'is_current' => $request->user()?->tenant_id === $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
