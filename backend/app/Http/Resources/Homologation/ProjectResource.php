<?php

namespace App\Http\Resources\Homologation;

use App\Domain\Projects\Models\SolarProject;
use App\Http\Resources\Registry\ConsumerUnitResource;
use App\Http\Resources\Registry\TechnicalResponsibleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolarProject
 */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name, 'installation_type' => $this->installation_type, 'status' => $this->status,
            'generation_type' => $this->generation_type,
            'modality' => $this->modality,
            'modality_label' => SolarProject::MODALITIES[$this->modality] ?? $this->modality,
            'installed_power_kwp' => (float) $this->installed_power_kwp,
            'inverter_power_kw' => (float) $this->inverter_power_kw,
            'access_power_kw' => $this->accessPowerKw(),
            'has_battery' => $this->has_battery,
            'estimated_generation_kwh_month' => $this->estimated_generation_kwh_month !== null ? (float) $this->estimated_generation_kwh_month : null,
            'notes' => $this->notes,
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->uuid, 'name' => $this->client->name, 'document' => $this->client->document]),
            'consumer_unit' => $this->whenLoaded('consumerUnit', fn () => ConsumerUnitResource::make($this->consumerUnit)->toArray($request)),
            'technical_responsible' => $this->whenLoaded('technicalResponsible', fn () => $this->technicalResponsible
                ? TechnicalResponsibleResource::make($this->technicalResponsible)->toArray($request)
                : null),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment->map(fn ($item) => [
                'id' => $item->uuid,
                'type' => $item->type,
                'manufacturer' => $item->manufacturer,
                'model' => $item->model,
                'power_w' => $item->power_w !== null ? (float) $item->power_w : null,
                'quantity' => (int) $item->pivot->getAttribute('quantity'),
            ])->values()),
            'process' => $this->whenLoaded('process', fn () => $this->process ? [
                'id' => $this->process->uuid,
                'code' => $this->process->code,
                'status' => $this->process->status->value,
                'status_label' => $this->process->status->label(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'processes' => $this->whenLoaded('processes', fn () => $this->processes->map(fn ($p) => ['id' => $p->uuid, 'code' => $p->code, 'status' => $p->status->value])->values()),
        ];
    }
}
