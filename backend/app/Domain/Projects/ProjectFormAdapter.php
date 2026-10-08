<?php

namespace App\Domain\Projects;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Projects\Enums\CompensationMode;
use App\Domain\Projects\Enums\ResponsibilityPurpose;
use App\Domain\Projects\Models\CompensationConfig;
use App\Domain\Projects\Models\ServiceRequest;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Accepts both project forms while maintaining one set of project relationships. */
final class ProjectFormAdapter
{
    public const MODES = [
        'LOCAL_SELF_CONSUMPTION' => 'autoconsumo_local',
        'REMOTE_SELF_CONSUMPTION' => 'autoconsumo_remoto',
        'SHARED_GENERATION' => 'geracao_compartilhada',
        'MULTIPLE_UNITS' => 'multiplas_uc',
    ];

    public function normalize(Request $request): void
    {
        if (! $request->has('compensation_mode')) {
            return;
        }
        $data = $request->validate([
            'compensation_mode' => ['required', Rule::enum(CompensationMode::class)],
            'equipment' => ['array', 'max:30'], 'equipment.*.id' => ['required', 'uuid', 'distinct'],
            'equipment.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'project_rt' => ['nullable', 'array:id,art_number'], 'project_rt.id' => ['required_with:project_rt', 'uuid'],
        ]);
        $equipment = EquipmentItem::whereIn('uuid', array_column($data['equipment'] ?? [], 'id'))->get()->keyBy('uuid');
        $dc = $ac = 0.0;
        foreach ($data['equipment'] ?? [] as $selected) {
            $item = $equipment->get($selected['id']);
            if ($item?->type === 'module') {
                $dc += (float) $item->power_w * (int) $selected['quantity'] / 1000;
            }
            if ($item?->type === 'inverter') {
                $ac += (float) ($item->nominal_ac_power_kw ?? ((float) $item->power_w / 1000)) * (int) $selected['quantity'];
            }
        }
        $request->merge([
            'modality' => self::MODES[$data['compensation_mode']],
            'installed_power_kwp' => round($dc, 3), 'inverter_power_kw' => round($ac, 3),
            'technical_responsible_id' => $data['project_rt']['id'] ?? null,
        ]);
    }

    public function persist(Request $request, SolarProject $project): void
    {
        $project->update(['compensation_mode' => array_search($project->modality, self::MODES, true),
            'considered_power_kw' => min((float) $project->installed_power_kwp, (float) $project->inverter_power_kw)]);
        if (! $request->has('compensation_mode')) {
            if ($project->technical_responsible_id) {
                $current = $project->responsibilities()->where('purpose', ResponsibilityPurpose::Project->value)->first();
                $project->responsibilities()->updateOrCreate(['purpose' => ResponsibilityPurpose::Project], ['technical_responsible_id' => $project->technical_responsible_id, 'art_number' => $current?->technical_responsible_id === $project->technical_responsible_id ? $current->art_number : null]);
            } else {
                $project->responsibilities()->where('purpose', ResponsibilityPurpose::Project->value)->delete();
            }

            return;
        }
        $data = $request->validate([
            'initial_protocol' => ['nullable', 'string', 'max:80'], 'source_type' => ['sometimes', Rule::in(['SOLAR'])],
            'storage_energy_kwh' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'has_dispatch_controller' => ['sometimes', 'boolean'], 'declared_dispatchable' => ['sometimes', 'boolean'],
            'has_coupling_transformer' => ['sometimes', 'boolean'],
            'compensation_method' => ['nullable', Rule::in(['PERCENTAGE', 'PRIORITY'])],
            'compensation_units' => ['array', 'max:100'], 'compensation_units.*' => ['array:consumer_unit_id,percentage,priority'],
            'compensation_units.*.consumer_unit_id' => ['required', 'uuid', 'distinct'],
            'compensation_units.*.percentage' => ['nullable', 'numeric', 'between:0,100'], 'compensation_units.*.priority' => ['nullable', 'integer', 'min:1'],
            'project_rt' => ['nullable', 'array:id,art_number'], 'project_rt.id' => ['required_with:project_rt', 'uuid'],
            'project_rt.art_number' => ['nullable', 'string', 'max:60'],
            'execution_rt' => ['nullable', 'array:id,art_number'], 'execution_rt.id' => ['required_with:execution_rt', 'uuid'],
            'execution_rt.art_number' => ['nullable', 'string', 'max:60'],
        ]);
        $project->update(array_intersect_key($data, array_flip(['source_type', 'storage_energy_kwh', 'has_dispatch_controller', 'declared_dispatchable', 'has_coupling_transformer', 'compensation_method'])));
        foreach (['PROJECT' => 'project_rt', 'EXECUTION' => 'execution_rt'] as $purpose => $field) {
            $project->responsibilities()->where('purpose', $purpose)->delete();
            if (! empty($data[$field]['id'])) {
                $rt = TechnicalResponsible::where('uuid', $data[$field]['id'])->where('active', true)->firstOrFail();
                $project->responsibilities()->create(['purpose' => $purpose, 'technical_responsible_id' => $rt->id, 'art_number' => $data[$field]['art_number'] ?? null, 'created_by' => $request->user()->id]);
            }
        }
        $allocation = isset($data['compensation_method']) ? strtolower($data['compensation_method']) : ($project->compensation?->allocation_rule ?? 'percentage');
        $config = CompensationConfig::updateOrCreate(['solar_project_id' => $project->id], ['mode' => $project->modality, 'allocation_rule' => $allocation]);
        $project->update(['compensation_method'=>strtoupper($allocation)]);
        if (array_key_exists('compensation_units', $data)) {
            $config->units()->delete();
        }
        foreach ($data['compensation_units'] ?? [] as $unit) {
            $consumer = ConsumerUnit::where('uuid', $unit['consumer_unit_id'])->where('active', true)->firstOrFail();
            if ($project->modality === 'autoconsumo_local' && $consumer->id !== $project->consumer_unit_id) {
                throw new DomainException('Autoconsumo local deve beneficiar somente a UC do projeto.', 'local_compensation_mismatch');
            }
            $config->units()->create(['solar_project_id' => $project->id, 'consumer_unit_id' => $consumer->id, 'percentage' => $unit['percentage'] ?? null, 'priority' => $unit['priority'] ?? null]);
        }
        $protocol = trim($data['initial_protocol'] ?? '');
        if ($protocol !== '') {
            $service = ServiceRequest::firstOrCreate(['consumer_unit_id' => $project->consumer_unit_id, 'distributor_id' => $project->consumerUnit->distributor_id, 'protocol_number' => $protocol], ['created_by' => $request->user()->id]);
            $project->update(['service_request_id' => $service->id]);
        } else {
            $project->update(['service_request_id' => null]);
        }
        $project->unsetRelations();
        app(ProjectTechnicalData::class)->invalidateManualChecks($project);
    }
}
