<?php

namespace App\Domain\Projects;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Homologations\Models\ChecklistItem;
use App\Domain\Projects\Models\CompensationConfig;
use App\Domain\Projects\Models\CompensationUnit;
use App\Domain\Projects\Models\ProjectConnectionData;
use App\Domain\Projects\Models\ProjectInverter;
use App\Domain\Projects\Models\ProjectStorage;
use App\Domain\Projects\Models\SolarArray;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ProjectTechnicalData
{
    public const RELATIONS = ['connectionData', 'arrays.module', 'inverters.inverter', 'storage.battery', 'compensation.units.consumerUnit', 'responsibilityTerms.file', 'responsibilityTerms.responsible'];

    /** @return array<string, mixed> */
    public function data(SolarProject $project): array
    {
        $project->loadMissing(self::RELATIONS);

        /** @var CompensationConfig|null $compensation */
        $compensation = $project->getRelation('compensation');

        return [
            'name' => $project->name, 'installation_type' => $project->installation_type,
            'connection' => $project->connectionData?->only(['connection_point', 'supply_voltage', 'phase_configuration', 'main_breaker_a', 'installed_load_kw', 'contracted_demand_kw', 'existing_generation_kw', 'emergency_generator']) ?? [],
            'arrays' => $project->arrays->map(fn ($row) => [...$row->only(['module_quantity', 'strings_quantity', 'modules_per_string', 'azimuth', 'tilt']), 'id' => $row->uuid, 'module_model_id' => $row->module->uuid])->values()->all(),
            'inverters' => $project->inverters->map(fn ($row) => [...$row->only(['quantity', 'nominal_ac_kw', 'connection_voltage', 'protection_config_json']), 'id' => $row->uuid, 'inverter_model_id' => $row->inverter->uuid])->values()->all(),
            'storage' => $project->storage->map(fn ($row) => [...$row->only(['quantity', 'energy_kwh', 'power_kw', 'dispatchable', 'operating_strategy']), 'id' => $row->uuid, 'battery_model_id' => $row->battery->uuid])->values()->all(),
            'compensation' => ['mode' => $compensation !== null ? $compensation->mode : $project->modality, 'allocation_rule' => $compensation !== null ? $compensation->allocation_rule : 'percentage',
                'units' => $compensation?->units->map(fn ($u) => ['consumer_unit_id' => $u->consumerUnit->uuid, 'percentage' => $u->percentage, 'priority' => $u->priority])->values()->all() ?? []],
            'responsibility_terms' => $project->responsibilityTerms->map(fn ($term) => [...$term->only(['type', 'number']), 'id' => $term->uuid,
                'issued_at' => $term->issued_at->toDateString(), 'valid_until' => $term->valid_until?->toDateString(), 'technical_responsible_id' => $term->responsible->uuid, 'file_id' => $term->file->uuid])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $input */
    public function sync(SolarProject $project, array $input): void
    {
        $data = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255'], 'installation_type' => ['nullable', Rule::in(['rooftop', 'ground', 'other'])],
            'connection' => ['array:connection_point,supply_voltage,phase_configuration,main_breaker_a,installed_load_kw,contracted_demand_kw,existing_generation_kw,emergency_generator'], 'connection.connection_point' => ['nullable', 'string', 'max:255'],
            'connection.supply_voltage' => ['nullable', 'numeric', 'gt:0'], 'connection.phase_configuration' => ['nullable', Rule::in(['monofasico', 'bifasico', 'trifasico'])],
            'connection.main_breaker_a' => ['nullable', 'integer', 'min:1'], 'connection.installed_load_kw' => ['nullable', 'numeric', 'min:0'],
            'connection.contracted_demand_kw' => ['nullable', 'numeric', 'min:0'], 'connection.existing_generation_kw' => ['nullable', 'numeric', 'min:0'], 'connection.emergency_generator' => ['sometimes', 'boolean'],
            'arrays' => ['array', 'max:100'], 'arrays.*.module_model_id' => ['required', 'uuid'], 'arrays.*.module_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'arrays.*' => ['array:module_model_id,module_quantity,strings_quantity,modules_per_string,azimuth,tilt'],
            'arrays.*.strings_quantity' => ['nullable', 'integer', 'min:1'], 'arrays.*.modules_per_string' => ['nullable', 'integer', 'min:1'], 'arrays.*.azimuth' => ['nullable', 'numeric', 'between:0,360'], 'arrays.*.tilt' => ['nullable', 'numeric', 'between:0,90'],
            'inverters' => ['array', 'max:100'], 'inverters.*.inverter_model_id' => ['required', 'uuid'], 'inverters.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'inverters.*' => ['array:inverter_model_id,quantity,nominal_ac_kw,connection_voltage,protection_config_json'],
            'inverters.*.nominal_ac_kw' => ['required', 'numeric', 'gt:0'], 'inverters.*.connection_voltage' => ['nullable', 'numeric', 'gt:0'], 'inverters.*.protection_config_json' => ['nullable', 'array'],
            'storage' => ['array', 'max:100'], 'storage.*.battery_model_id' => ['required', 'uuid'], 'storage.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'storage.*' => ['array:battery_model_id,quantity,energy_kwh,power_kw,dispatchable,operating_strategy'],
            'storage.*.energy_kwh' => ['nullable', 'numeric', 'gt:0'], 'storage.*.power_kw' => ['nullable', 'numeric', 'gt:0'], 'storage.*.dispatchable' => ['sometimes', 'boolean'], 'storage.*.operating_strategy' => ['nullable', 'string', 'max:2000'],
            'compensation' => ['array:mode,allocation_rule,units'], 'compensation.mode' => ['required_with:compensation', Rule::in(array_keys(SolarProject::MODALITIES))],
            'compensation.allocation_rule' => ['required_with:compensation', Rule::in(['percentage', 'priority'])], 'compensation.units' => ['array', 'max:100'],
            'compensation.units.*.consumer_unit_id' => ['required', 'uuid', 'distinct'], 'compensation.units.*.percentage' => ['nullable', 'numeric', 'between:0,100'], 'compensation.units.*.priority' => ['nullable', 'integer', 'min:1'],
            'compensation.units.*' => ['array:consumer_unit_id,percentage,priority'],
        ])->validate();
        DB::transaction(function () use ($project, $data): void {
            SolarProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $project->update(array_intersect_key($data, array_flip(['name', 'installation_type'])));
            if (isset($data['connection'])) {
                ProjectConnectionData::updateOrCreate(['solar_project_id' => $project->id], $data['connection']);
            }
            foreach (['arrays' => [SolarArray::class, 'module_model_id', 'module'], 'inverters' => [ProjectInverter::class, 'inverter_model_id', 'inverter'], 'storage' => [ProjectStorage::class, 'battery_model_id', 'battery']] as $key => [$class, $field, $type]) {
                if (! array_key_exists($key, $data)) {
                    continue;
                }
                $rows = [];
                foreach ($data[$key] as $row) {
                    $equipment = EquipmentItem::query()->where('uuid', $row[$field])->where('type', $type)->where('active', true)->firstOrFail();
                    $row[$field] = $equipment->id;
                    if ($key === 'arrays' && ! empty($row['strings_quantity']) && ! empty($row['modules_per_string']) && (int) $row['strings_quantity'] * (int) $row['modules_per_string'] !== (int) $row['module_quantity']) {
                        throw new DomainException('Quantidade de módulos incompatível com strings e módulos por string.', 'array_quantity_mismatch');
                    }
                    if ($key === 'inverters' && abs((float) $row['nominal_ac_kw'] - (float) $equipment->power_w / 1000) > 0.001) {
                        throw new DomainException('A potência nominal do inversor deve corresponder ao modelo do catálogo.', 'inverter_power_mismatch');
                    }
                    $rows[] = $row;
                }
                $class::query()->where('solar_project_id', $project->id)->delete();
                foreach ($rows as $row) {
                    $class::create(['solar_project_id' => $project->id, ...$row]);
                }
            }
            if (isset($data['compensation'])) {
                $config = CompensationConfig::updateOrCreate(['solar_project_id' => $project->id], array_intersect_key($data['compensation'], array_flip(['mode', 'allocation_rule'])));
                CompensationUnit::query()->where('compensation_config_id', $config->id)->delete();
                foreach ($data['compensation']['units'] ?? [] as $unit) {
                    $consumer = ConsumerUnit::query()->where('uuid', $unit['consumer_unit_id'])->where('active', true)->firstOrFail();
                    if ($config->mode === 'autoconsumo_local' && $consumer->id !== $project->consumer_unit_id) {
                        throw new DomainException('Autoconsumo local deve beneficiar somente a UC do projeto.', 'local_compensation_mismatch');
                    }
                    CompensationUnit::create([...$unit, 'solar_project_id' => $project->id, 'compensation_config_id' => $config->id, 'consumer_unit_id' => $consumer->id]);
                }
                if (! empty($data['compensation']['units'])) {
                    $config->fresh()->validateAllocation();
                }
                $project->modality = $config->mode;
            }
            $project->unsetRelations();
            $project->load(self::RELATIONS);
            $pivot = [];
            foreach ($project->arrays as $row) {
                $pivot[$row->module_model_id]['quantity'] = ($pivot[$row->module_model_id]['quantity'] ?? 0) + $row->module_quantity;
            }
            foreach ($project->inverters as $row) {
                $pivot[$row->inverter_model_id]['quantity'] = ($pivot[$row->inverter_model_id]['quantity'] ?? 0) + $row->quantity;
            }
            foreach ($project->storage as $row) {
                $pivot[$row->battery_model_id]['quantity'] = ($pivot[$row->battery_model_id]['quantity'] ?? 0) + $row->quantity;
            }
            foreach ($pivot as &$entry) {
                $entry['tenant_id'] = $project->tenant_id;
            }
            unset($entry);
            $project->equipment()->sync($pivot);
            $project->installed_power_kwp = number_format($project->arrays->sum(fn ($array) => $array->dcPower()), 3, '.', '');
            $project->inverter_power_kw = number_format($project->inverters->sum(fn ($inverter) => $inverter->totalAcPower()), 3, '.', '');
            $project->generation_type = SolarProject::classify($project->accessPowerKw());
            $project->has_battery = $project->storage->isNotEmpty();
            $project->status = 'rascunho';
            $project->save();
            $project->touch();
            $this->invalidateManualChecks($project);
        });
    }

    public function seedFromEquipment(SolarProject $project): void
    {
        foreach ($project->equipment as $equipment) {
            $quantity = $equipment->pivot->getAttribute('quantity');
            if ($equipment->type === 'module') {
                SolarArray::firstOrCreate(['solar_project_id' => $project->id, 'module_model_id' => $equipment->id], ['module_quantity' => $quantity]);
            }
            if ($equipment->type === 'inverter') {
                ProjectInverter::firstOrCreate(['solar_project_id' => $project->id, 'inverter_model_id' => $equipment->id], ['quantity' => $quantity, 'nominal_ac_kw' => (float) $equipment->power_w / 1000]);
            }
            if ($equipment->type === 'battery') {
                ProjectStorage::firstOrCreate(['solar_project_id' => $project->id, 'battery_model_id' => $equipment->id], ['quantity' => $quantity, 'energy_kwh' => $equipment->energy_kwh]);
            }
        }
        ProjectConnectionData::firstOrCreate(['solar_project_id' => $project->id], ['phase_configuration' => $project->consumerUnit->supply_type, 'main_breaker_a' => $project->consumerUnit->breaker_a,
            'supply_voltage' => $project->consumerUnit->voltage, 'installed_load_kw' => $project->consumerUnit->installed_load_kw, 'contracted_demand_kw' => $project->consumerUnit->contracted_demand_kw]);
        $config = CompensationConfig::firstOrCreate(['solar_project_id' => $project->id], ['mode' => $project->modality, 'allocation_rule' => 'percentage']);
        if ($config->mode === 'autoconsumo_local') {
            CompensationUnit::firstOrCreate(['compensation_config_id' => $config->id, 'consumer_unit_id' => $project->consumer_unit_id], ['solar_project_id' => $project->id, 'percentage' => 100]);
        }
    }

    /** @return list<string> */
    public function issues(SolarProject $project): array
    {
        $project->loadMissing(self::RELATIONS);
        $issues = [];
        if (! $project->installation_type) {
            $issues[] = 'Informe o tipo de instalação (telhado, solo ou outra).';
        }
        if ($project->arrays->isEmpty()) {
            $issues[] = 'Cadastre pelo menos um arranjo fotovoltaico.';
        }
        foreach ($project->arrays as $row) {
            if ($row->azimuth === null || $row->tilt === null || ! $row->strings_quantity || ! $row->modules_per_string) {
                $issues[] = 'Complete orientação, inclinação e strings dos arranjos.';
            }
        }
        if ($project->inverters->isEmpty()) {
            $issues[] = 'Cadastre os inversores do projeto.';
        }
        foreach ($project->inverters as $row) {
            if (! $row->connection_voltage || empty($row->protection_config_json)) {
                $issues[] = 'Complete tensão e configuração de proteção dos inversores.';
            }
        }
        $connection = $project->connectionData;
        if (! $connection || ! $connection->connection_point || ! $connection->supply_voltage || ! $connection->phase_configuration || ! $connection->main_breaker_a || $connection->installed_load_kw === null) {
            $issues[] = 'Complete os dados de conexão e a carga instalada.';
        }
        if (abs((float) $project->installed_power_kwp - $project->arrays->sum(fn ($row) => $row->dcPower())) > 0.001 || abs((float) $project->inverter_power_kw - $project->inverters->sum(fn ($row) => $row->totalAcPower())) > 0.001) {
            $issues[] = 'As potências do projeto devem corresponder aos equipamentos instalados.';
        }
        if ($project->has_battery) {
            try {
                (new StorageSystem($project))->validateStrategy();
            } catch (DomainException $e) {
                $issues[] = $e->getMessage();
            }
        }
        if (! $project->compensation) {
            $issues[] = 'Configure a compensação de energia.';
        } else {
            try {
                $project->compensation->validateAllocation();
            } catch (DomainException $e) {
                $issues[] = $e->getMessage();
            }
        }
        if ($project->accessPowerKw() > 5000 || $project->accessPowerKw() <= 0) {
            $issues[] = 'A potência de acesso deve estar entre zero e 5.000 kW.';
        }
        $term = $project->responsibilityTerms->first(fn ($term) => $term->technical_responsible_id === $project->technical_responsible_id && (! $term->valid_until || ! $term->valid_until->isBefore(today())) && $term->file->isValid());
        if (! $term) {
            $issues[] = 'Vincule uma ART/TRT aprovada e vigente ao responsável técnico.';
        }

        return array_values(array_unique($issues));
    }

    public function invalidateManualChecks(SolarProject $project): void
    {
        ChecklistItem::whereIn('homologation_process_id', $project->processes()->select('id'))
            ->whereHas('requirement', fn ($q) => $q->whereNull('required_document_type'))
            ->update(['status' => 'pendente', 'validated_at' => null, 'validated_by' => null]);
    }
}
