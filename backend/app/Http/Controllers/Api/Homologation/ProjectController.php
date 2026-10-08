<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\SolarProject;
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

        return ProjectResource::make($project->load(['client', 'consumerUnit.distributor', 'technicalResponsible', 'equipment', 'process']));
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

            $unit = ConsumerUnit::query()->findOrFail($project->consumer_unit_id);
            $process = HomologationProcess::create([
                'solar_project_id' => $project->id,
                'distributor_id' => $unit->distributor_id,
                'assigned_user_id' => $request->user()->id,
                'code' => SequentialCode::next(HomologationProcess::class, 'HOM'),
                'status' => ProcessStatus::Rascunho,
            ]);
            $process->history()->create([
                'tenant_id' => $process->tenant_id,
                'from_status' => null,
                'to_status' => ProcessStatus::Rascunho->value,
                'user_id' => $request->user()->id,
                'reason' => 'Processo aberto com o cadastro do projeto.',
            ]);

            return $project;
        });

        return ProjectResource::make($project->load(['client', 'consumerUnit.distributor', 'technicalResponsible', 'equipment', 'process']))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, SolarProject $project): ProjectResource
    {
        $this->authorize('projects.manage');

        $process = $project->process;
        if ($process && ! $process->status->isEditable()) {
            throw new DomainException(
                "O projeto não pode ser alterado com o processo em \"{$process->status->label()}\".",
                'project_locked',
            );
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($project, $data, $process): void {
            $project->update($data['attributes']);
            $this->syncEquipment($project, $data['equipment']);

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

        $equipmentIds = EquipmentItem::query()
            ->whereIn('uuid', collect($data['equipment'] ?? [])->pluck('id'))
            ->where('active', true)
            ->pluck('id', 'uuid');

        $equipment = collect($data['equipment'] ?? [])->map(function ($e) use ($equipmentIds) {
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
