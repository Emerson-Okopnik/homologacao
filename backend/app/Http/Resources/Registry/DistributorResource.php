<?php

namespace App\Http\Resources\Registry;

use App\Domain\Distributors\Models\Distributor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Distributor
 */
final class DistributorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'state' => $this->state,
            'integration_mode' => $this->integration_mode->value,
            'integration_mode_label' => $this->integration_mode->label(),
            'portal_url' => $this->portal_url,
            // Nunca expõe o segredo; apenas se há credencial configurada.
            'has_credential' => $this->secret_ref !== null,
            'active' => $this->active,
        ];
    }
}
