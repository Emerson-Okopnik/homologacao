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
        $num = fn ($v) => $v !== null ? (float) $v : null;

        return [
            'source_type' => $this->source_type,
            'modules_power_kwp' => (float) $this->installed_power_kwp,
            'inverters_power_kw' => (float) $this->inverter_power_kw,
            'considered_power_kw' => (float) $this->considered_power_kw,
            'classification' => $this->classification?->value,
            'classification_label' => $this->classification?->label() ?? 'Sem classificação',
            'fast_track_eligible' => (bool) $this->fast_track_eligible,
            'storage_energy_kwh' => $num($this->storage_energy_kwh),
            'has_dispatch_controller' => (bool) $this->has_dispatch_controller,
            'declared_dispatchable' => (bool) $this->declared_dispatchable,
            'has_coupling_transformer' => (bool) $this->has_coupling_transformer,
            'compensation_mode' => $this->compensation_mode->value,
            'compensation_mode_label' => $this->compensation_mode->label(),
            'compensation_method' => $this->compensation_method,
            'initial_protocol' => $this->whenLoaded('serviceRequest', fn () => $this->serviceRequest?->protocol_number),
            'responsibilities' => $this->whenLoaded('responsibilities', fn () => $this->responsibilities->map(fn ($r) => [
                'purpose' => $r->purpose->value,
                'purpose_label' => $r->purpose->label(),
                'art_number' => $r->art_number,
                'responsible' => [
                    'id' => $r->responsible->uuid,
                    'name' => $r->responsible->name,
                    'council' => $r->responsible->council,
                    'registration' => $r->responsible->registration,
                    'registration_status' => $r->responsible->registration_status,
                ],
            ])->values()),
            'compensation_units' => $this->whenLoaded('compensationUnits', fn () => $this->compensationUnits->map(fn ($u) => [
                'consumer_unit_id' => $u->consumerUnit->uuid,
                'number' => $u->consumerUnit->number,
                'percentage' => $num($u->percentage),
                'priority' => $u->priority,
            ])->values()),
            'fast_track_acceptances' => $this->whenLoaded('fastTrackAcceptances', fn () => $this->fastTrackAcceptances
                ->whereNull('revoked_at')->map(fn ($a) => [
                    'party' => $a->party->value,
                    'party_label' => $a->party->label(),
                    'signer_name' => $a->signer_name,
                    'statement_version' => $a->statement_version,
                    'accepted_at' => $a->accepted_at->toIso8601String(),
                ])->values()),
            'waivers' => $this->whenLoaded('waivers', fn () => $this->waivers->whereNull('revoked_at')->map(fn ($w) => [
                'requirement_code' => $w->requirement_code, 'reason' => $w->reason,
            ])->values()),
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
                'nominal_ac_power_kw' => $item->nominal_ac_power_kw !== null ? (float) $item->nominal_ac_power_kw : null, 'has_inmetro_registration' => (bool) $item->has_inmetro_registration,
                'power_w' => $item->power_w !== null ? (float) $item->power_w : null,
                'quantity' => (int) $item->pivot->getAttribute('quantity'),
            ])->values()),
            'process' => $this->whenLoaded('process', fn () => $this->process ? [
                'id' => $this->process->uuid,
                'code' => $this->process->code,
                'status' => $this->process->status->value,
                'status_label' => $this->process->status->label(), 'stage' => $this->process->stage->value, 'stage_label' => $this->process->stage->label(), 'editable' => $this->resource->isEditable(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'processes' => $this->whenLoaded('processes', fn () => $this->processes->map(fn ($p) => ['id' => $p->uuid, 'code' => $p->code, 'status' => $p->status->value])->values()),
        ];
    }
}
