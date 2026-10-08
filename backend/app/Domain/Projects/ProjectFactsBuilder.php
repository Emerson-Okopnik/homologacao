<?php

namespace App\Domain\Projects;

use App\Domain\Homologations\Enums\ConnectionEventType;
use App\Domain\Homologations\Enums\InspectionStatus;
use App\Domain\Homologations\Enums\NetworkWorkStatus;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Enums\CompensationMode;
use App\Domain\Projects\Enums\ResponsibilityPurpose;
use App\Domain\Projects\Models\SolarProject;

/**
 * Monta o conjunto de fatos (somente os do FactCatalog) que as regras avaliam.
 */
final class ProjectFactsBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(SolarProject $project, ?HomologationProcess $process = null): array
    {
        $project->loadMissing(['equipment', 'consumerUnit.distributor', 'responsibilities.responsible', 'compensationUnits']);
        $process ??= $project->process;

        $modulesKwp = $this->modulesPowerKwp($project);
        $invertersKw = $this->invertersPowerKw($project);
        $considered = $this->consideredPower($modulesKwp, $invertersKw);

        $inverters = $project->equipment->where('type', 'inverter');
        $storageKwh = (float) ($project->storage_energy_kwh ?? 0);
        $monthly = (float) ($project->estimated_generation_kwh_month ?? 0);

        $projectRt = $project->responsibility(ResponsibilityPurpose::Project);
        $executionRt = $project->responsibility(ResponsibilityPurpose::Execution);

        $facts = [
            'considered_power_kw' => $considered,
            'modules_power_kwp' => $modulesKwp,
            'inverters_power_kw' => $invertersKw,
            'source_type' => $project->source_type,
            'classification' => $project->classification?->value,
            'classification_group' => $project->classification?->group(),
            'connection_voltage' => $project->consumerUnit?->voltage_class === 'MT' ? 'MEDIUM' : 'LOW',
            'distributor_code' => $project->consumerUnit?->distributor?->code,
            'has_storage' => (bool) $project->has_battery,
            'storage_ratio_monthly' => $monthly > 0 ? round($storageKwh / $monthly, 4) : 0.0,
            'has_dispatch_controller' => (bool) $project->has_dispatch_controller,
            'declared_dispatchable' => (bool) $project->declared_dispatchable,
            'has_coupling_transformer' => (bool) $project->has_coupling_transformer,
            'inverters_only' => ! $project->has_coupling_transformer && $inverters->isNotEmpty(),
            'has_credit_allocation' => $project->compensation_mode !== CompensationMode::LocalSelfConsumption,
            'compensation_mode' => $project->compensation_mode->value,
            'inverters_without_inmetro' => $inverters->where('has_inmetro_registration', false)->count(),
            'fast_track_eligible' => (bool) $project->fast_track_eligible,
            'has_initial_protocol' => $project->service_request_id !== null,
            'project_rt_defined' => $projectRt !== null,
            'project_rt_regular' => $projectRt !== null && $projectRt->responsible->active && $projectRt->responsible->registration_status === 'regular',
            'project_art_number_informed' => filled($projectRt?->art_number),
            'execution_rt_defined' => $executionRt !== null,
        ];

        $network = $process?->network_work_status ?? NetworkWorkStatus::UnderAnalysis;
        $lastInspection = $process?->inspections()->first();
        $events = $process ? $process->connectionEvents()->pluck('type') : collect();

        return [
            ...$facts,
            'network_work_status' => $network->value,
            'network_work_required' => $network->isRequired(),
            'network_work_cleared' => $network->isCleared(),
            'execution_reported' => $process?->execution()->exists() ?? false,
            'open_pendencies' => $process ? $process->openPendencies()->count() : 0,
            'last_inspection_approved' => $lastInspection?->status === InspectionStatus::Approved,
            'meter_installed' => $events->contains(ConnectionEventType::MeterInstalled),
            'system_connected' => $events->contains(ConnectionEventType::SystemConnected),
        ];
    }

    public function modulesPowerKwp(SolarProject $project): float
    {
        return round($project->equipment->where('type', 'module')
            ->sum(fn ($e) => (float) $e->power_w * (int) $e->pivot->quantity) / 1000, 3);
    }

    public function invertersPowerKw(SolarProject $project): float
    {
        return round($project->equipment->where('type', 'inverter')
            ->sum(fn ($e) => (float) ($e->nominal_ac_power_kw ?? ((float) $e->power_w / 1000)) * (int) $e->pivot->quantity), 3);
    }

    /** Potência considerada: o menor valor entre módulos (CC) e inversores (CA), quando ambos existem. */
    public function consideredPower(float $modulesKwp, float $invertersKw): float
    {
        if ($modulesKwp <= 0 || $invertersKw <= 0) {
            return max($modulesKwp, $invertersKw);
        }

        return min($modulesKwp, $invertersKw);
    }
}
