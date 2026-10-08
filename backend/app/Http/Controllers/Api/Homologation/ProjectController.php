<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Homologations\HomologationService;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Projects\ProjectTechnicalData;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\SequentialCode;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\ProjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('projects.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'client' => ['sometimes', 'nullable', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $projects = SolarProject::query()
            ->with(['client', 'consumerUnit.distributor', 'process'])
            ->when($filters['client'] ?? null, fn ($q, string $uuid) => $q->whereHas('client', fn ($c) => $c->where('uuid', $uuid)))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$s}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'ilike', "%{$s}%"))
                ->orWhereHas('consumerUnit', fn ($u) => $u->where('number', 'like', "%{$s}%"))))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return ProjectResource::collection($projects);
    }

    public function show(SolarProject $project): ProjectResource
    {
        $this->authorize('projects.view');

        return ProjectResource::make($project->load(['client', 'consumerUnit.distributor', 'technicalResponsible', 'equipment', 'process', 'processes']));
    }

    /**
     * Criar o projeto já abre o processo de homologação vinculado à distribuidora da UC.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('projects.manage');

        $data = $this->validated($request);

        $project = DB::transaction(function () use ($data, $request): SolarProject {
            $project = SolarProject::create([
                ...$data['attributes'],
                'code' => SequentialCode::next(SolarProject::class, 'PRJ'),
                'created_by' => $request->user()->id,
            ]);
            $this->syncEquipment($project, $data['equipment']);

            $project->refresh()->load('equipment', 'consumerUnit');
            app(ProjectTechnicalData::class)->seedFromEquipment($project);
            app(HomologationService::class)->open($project, $request->user());

            return $project;
        });

        return ProjectResource::make($project->refresh()->load(['client', 'consumerUnit.distributor', 'technicalResponsible', 'equipment', 'process', 'processes']))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, SolarProject $project): ProjectResource
    {
        $this->authorize('projects.manage');

        $project->assertEditable();
        $process = $project->process;

        $data = $this->validated($request);

        DB::transaction(function () use ($project, $data, $process): void {
            SolarProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail()->assertEditable();
            $before = $project->equipment()->get()->mapWithKeys(fn ($e) => [$e->id => (int) $e->pivot->getAttribute('quantity')])->all();
            $after = collect($data['equipment'])->mapWithKeys(fn ($e) => [$e['id'] => $e['quantity']])->all();
            $project->update($data['attributes']);
            if ($project->wasChanged('consumer_unit_id')) {
                $project->connectionData()->delete();
            }
            if ($project->wasChanged('consumer_unit_id') || $project->wasChanged('modality')) {
                $project->compensation?->units()->delete();
                $project->compensation()->update(['mode' => $project->modality]);
            }
            $this->syncEquipment($project, $data['equipment']);
            if ($before != $after) {
                $project->arrays()->delete();
                $project->inverters()->delete();
                $project->storage()->delete();
            }
            $project->unsetRelations()->load('equipment', 'consumerUnit');
            app(ProjectTechnicalData::class)->seedFromEquipment($project);

            if ($process) {
                $unit = ConsumerUnit::query()->findOrFail($project->consumer_unit_id);
                $process->update(['distributor_id' => $unit->distributor_id]);
            }
        });

        return ProjectResource::make($project->refresh()->load(['client', 'consumerUnit.distributor', 'technicalResponsible', 'equipment', 'process']));
    }

    /**
     * @param  list<array{id: int, quantity: int}>  $equipment
     */
    private function syncEquipment(SolarProject $project, array $equipment): void
    {
        $project->equipment()->sync(collect($equipment)->mapWithKeys(fn ($e) => [
            $e['id'] => ['quantity' => $e['quantity'], 'tenant_id' => $project->tenant_id],
        ])->all());
    }

    /**
     * @return array{attributes: array<string, mixed>, equipment: list<array{id: int, quantity: int}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'installation_type' => ['nullable', Rule::in(['rooftop', 'ground', 'other'])],
            'consumer_unit_id' => ['required', 'uuid'],
            'technical_responsible_id' => ['nullable', 'uuid'],
            'modality' => ['required', Rule::in(array_keys(SolarProject::MODALITIES))],
            'installed_power_kwp' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'inverter_power_kw' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'has_battery' => ['sometimes', 'boolean'],
            'estimated_generation_kwh_month' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'equipment' => ['array', 'max:30'],
            'equipment.*.id' => ['required', 'uuid', 'distinct'],
            'equipment.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $client = Client::query()->where('uuid', $data['client_id'])->firstOrFail();
        $unit = ConsumerUnit::query()->where('uuid', $data['consumer_unit_id'])->firstOrFail();

        if ($unit->client_id !== $client->id) {
            throw new DomainException('A unidade consumidora não pertence ao cliente informado.', 'unit_client_mismatch');
        }
        if (! $unit->active) {
            throw new DomainException('A unidade consumidora está inativa.', 'unit_inactive');
        }

        $rtId = null;
        if (! empty($data['technical_responsible_id'])) {
            $rtId = TechnicalResponsible::query()->where('uuid', $data['technical_responsible_id'])->where('active', true)->firstOrFail()->id;
        }

        /** @var list<array{id: string, quantity: int}> $selectedEquipment */
        $selectedEquipment = $data['equipment'] ?? [];
        $equipmentIds = EquipmentItem::query()
            ->whereIn('uuid', collect($selectedEquipment)->pluck('id'))
            ->where('active', true)
            ->pluck('id', 'uuid');

        $equipment = collect($selectedEquipment)->map(function ($e) use ($equipmentIds) {
            if (! $equipmentIds->has($e['id'])) {
                throw new DomainException('Equipamento inválido ou inativo no catálogo.', 'equipment_invalid');
            }

            return ['id' => (int) $equipmentIds->get($e['id']), 'quantity' => (int) $e['quantity']];
        })->values()->all();

        $installed = (float) $data['installed_power_kwp'];
        $inverter = (float) $data['inverter_power_kw'];
        $access = min($installed, $inverter);

        if ($access > 5000) {
            throw new DomainException('Potência acima do limite de minigeração distribuída (5 MW).', 'power_out_of_range');
        }

        return [
            'attributes' => [
                ...array_intersect_key($data, array_flip(['name', 'installation_type'])),
                'client_id' => $client->id,
                'consumer_unit_id' => $unit->id,
                'technical_responsible_id' => $rtId,
                'modality' => $data['modality'],
                'installed_power_kwp' => $installed,
                'inverter_power_kw' => $inverter,
                'generation_type' => SolarProject::classify($access),
                'has_battery' => (bool) ($data['has_battery'] ?? false),
                'estimated_generation_kwh_month' => $data['estimated_generation_kwh_month'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            'equipment' => $equipment,
        ];
    }
}
