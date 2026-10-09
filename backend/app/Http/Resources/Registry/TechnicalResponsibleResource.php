<?php

namespace App\Http\Resources\Registry;

use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TechnicalResponsible
 */
final class TechnicalResponsibleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'council' => $this->council,
            'registration' => $this->registration,
            'state' => $this->state,
            'email' => $this->email,
            'phone' => $this->phone,
            'registration_status' => $this->registration_status,
            'active' => $this->active,
            'user' => $this->user_id && ($user = \App\Domain\Users\Models\User::query()->find($this->user_id))
                ? ['id' => $user->uuid, 'name' => $user->name, 'email' => $user->email]
                : null,
            'projects_count' => $this->whenCounted('projects'),
        ];
    }
}
