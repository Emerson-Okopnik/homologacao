<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Homologations\HomologationService;
use App\Domain\Homologations\TimelineRecorder;
use App\Domain\Projects\Enums\FastTrackParty;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Projects\ProjectEvaluator;
use App\Domain\Projects\ProjectFormAdapter;
use App\Domain\Projects\ProjectTechnicalData;
use App\Domain\Rules\Enums\RequirementPhase;
use App\Domain\Rules\Models\RequirementRule;
use App\Domain\Rules\Models\RuleDecision;
use App\Domain\Rules\RequirementEngine;
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
    public const FAST_TRACK_STATEMENT_VERSION = '2026.1';

    public const FAST_TRACK_STATEMENT = 'Declaro ciência de que a solicitação seguirá o rito simplificado (Fast Track), '
        .'responsabilizando-me pela veracidade das informações e pela conformidade da instalação às normas da distribuidora.';

    private const RELATIONS = [
        'client', 'consumerUnit.distributor', 'serviceRequest', 'equipment',
        'responsibilities.responsible', 'compensationUnits.consumerUnit',
        'fastTrackAcceptances', 'waivers', 'process', 'processes', 'technicalResponsible',
    ];

    public function __construct(
        private readonly ProjectEvaluator $evaluator,
        private readonly RequirementEngine $requirements,
        private readonly TimelineRecorder $timeline,
    ) {}

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

        return ProjectResource::make($project->load(self::RELATIONS));
    }

    /**
     * Criar o projeto já abre o processo de homologação vinculado à distribuidora da UC.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('projects.manage');

        app(ProjectFormAdapter::class)->normalize($request);
        $data = $this->validated($request);
        $clientRequest = $this->clientRequestToConvert($request, $data);

        $project = DB::transaction(function () use ($data, $request, $clientRequest): SolarProject {
            $project = SolarProject::create([
                ...$data['attributes'],
                'code' => SequentialCode::next(SolarProject::class, 'PRJ'),
                'created_by' => $request->user()->id,
            ]);

            $this->syncRelations($project, $data, $request->user()->id);

            $assignedUserId = $clientRequest?->technicalResponsible?->user_id ?? $request->user()->id;

            $process = HomologationProcess::create([
                'solar_project_id' => $project->id,
                'distributor_id' => $data['unit']->distributor_id,
                'assigned_user_id' => $assignedUserId,
                'code' => SequentialCode::next(HomologationProcess::class, 'HOM'),
            ]);
            $process->forceFill([
                'status' => ProcessStatus::Active,
                'stage' => WorkflowStage::Preparation,
                'stage_changed_at' => now(),
            ])->save();

            $this->evaluator->evaluate($project);
            $this->timeline->record($process, 'CREATED', 'Processo aberto', 'Projeto cadastrado e em preparação.');

            if ($clientRequest) {
                $copied = app(\App\Domain\Documents\DocumentUploader::class)
                    ->copyCurrentLinks('client_request', $clientRequest, 'project', $project, $request->user()->id);
                $clientRequest->forceFill([
                    'status' => \App\Domain\Projects\Enums\ClientRequestStatus::Converted,
                    'solar_project_id' => $project->id,
                    'converted_at' => now(),
                ]);
                $clientRequest->addMessage('system', 'Sistema', "Solicitação convertida no projeto {$project->code}.");
                $clientRequest->save();
                $this->timeline->record($process, 'CLIENT_REQUEST_CONVERTED',
                    "Originado da solicitação {$clientRequest->code}", "{$copied} documento(s) do cliente reaproveitado(s).");
            }
            $this->syncEquipment($project, $data['equipment']);
            app(ProjectFormAdapter::class)->persist($request, $project);

            $project->refresh()->load('equipment', 'consumerUnit');
            app(ProjectTechnicalData::class)->seedFromEquipment($project);
            $opened = app(HomologationService::class)->open($project, $request->user());
            $this->timeline->record($opened, 'CREATED', 'Processo aberto');
            if ($request->has('compensation_mode')) {
                $this->evaluator->evaluate($project);
            }

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

        app(ProjectFormAdapter::class)->normalize($request);
        $data = $this->validated($request);

        DB::transaction(function () use ($project, $data, $process, $request): void {
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
            app(ProjectFormAdapter::class)->persist($request, $project);
            if ($before != $after) {
                $project->arrays()->delete();
                $project->inverters()->delete();
                $project->storage()->delete();
            }
            $project->unsetRelations()->load('equipment', 'consumerUnit');
            app(ProjectTechnicalData::class)->seedFromEquipment($project);

            if ($request->has('compensation_mode')) {
                $this->evaluator->evaluate($project);
            }
            if ($process) {
                $unit = ConsumerUnit::query()->findOrFail($project->consumer_unit_id);
                $process->update(['distributor_id' => $unit->distributor_id]);
            }
        });

        return ProjectResource::make($project->refresh()->load(self::RELATIONS));
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
            'installed_power_kwp' => ['required', 'numeric', $request->has('compensation_mode') ? 'min:0' : 'gt:0', 'max:5000'],
            'inverter_power_kw' => ['required', 'numeric', $request->has('compensation_mode') ? 'min:0' : 'gt:0', 'max:5000'],
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

    public function evaluation(Request $request, SolarProject $project): JsonResponse
    {
        $this->authorize('projects.view');

        $phase = RequirementPhase::tryFrom((string) $request->query('phase', 'SUBMISSION')) ?? RequirementPhase::Submission;
        $classification = $project->classification_decision_id ? RuleDecision::find($project->classification_decision_id) : null;
        $fastTrack = $project->fast_track_decision_id ? RuleDecision::find($project->fast_track_decision_id) : null;
        $checklist = $this->requirements->evaluate($project, $phase);

        return response()->json(['data' => [
            'powers' => [
                'modules_kwp' => (float) $project->installed_power_kwp,
                'inverters_kw' => (float) $project->inverter_power_kw,
                'considered_kw' => (float) $project->considered_power_kw,
            ],
            'classification' => [
                'value' => $project->classification?->value,
                'label' => $project->classification?->label(),
                'rule_code' => $classification?->rule_code,
                'rule_version' => $classification?->rule_version,
                'reason' => $classification?->result['reason'] ?? null,
                'decided_at' => $classification?->created_at?->toIso8601String(),
            ],
            'fast_track' => [
                'eligible' => (bool) $project->fast_track_eligible,
                'rule_code' => $fastTrack?->rule_code,
                'rule_version' => $fastTrack?->rule_version,
                'reasons' => $fastTrack?->result['reasons'] ?? [],
                'statement_version' => self::FAST_TRACK_STATEMENT_VERSION,
                'statement' => self::FAST_TRACK_STATEMENT,
            ],
            'checklist' => collect($checklist)->except('facts')->all(),
        ]]);
    }

    public function recordFastTrackAcceptance(Request $request, SolarProject $project): ProjectResource
    {
        $this->authorize('projects.manage');
        $this->assertEditable($project);

        if (! $project->fast_track_eligible) {
            throw new DomainException('O projeto não é elegível ao Fast Track pelas regras vigentes.', 'fast_track_not_eligible');
        }

        $data = $request->validate([
            'party' => ['required', Rule::enum(FastTrackParty::class)],
            'signer_name' => ['required', 'string', 'max:160'],
            'signer_document' => ['nullable', 'string', 'max:30'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'É necessário confirmar o aceite.']);

        DB::transaction(function () use ($project, $data, $request): void {
            $project->fastTrackAcceptances()->where('party', $data['party'])->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $project->fastTrackAcceptances()->create([
                'party' => $data['party'],
                'signer_name' => $data['signer_name'],
                'signer_document' => $data['signer_document'] ?? null,
                'statement_version' => self::FAST_TRACK_STATEMENT_VERSION,
                'statement_text' => self::FAST_TRACK_STATEMENT,
                'recorded_by' => $request->user()->id,
                'accepted_at' => now(),
            ]);

            if ($project->process) {
                $party = FastTrackParty::from($data['party'])->label();
                $this->timeline->record($project->process, 'FAST_TRACK_ACCEPTED', "Aceite Fast Track registrado ({$party})", $data['signer_name']);
            }
        });

        return ProjectResource::make($project->refresh()->load(self::RELATIONS));
    }

    public function recordWaiver(Request $request, SolarProject $project): ProjectResource
    {
        $this->authorize('homologations.manage');
        $this->assertEditable($project);

        $data = $request->validate([
            'requirement_code' => ['required', 'string', 'max:60'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $waivable = RequirementRule::query()->where('rule_code', $data['requirement_code'])->where('waivable', true)->exists();
        if (! $waivable) {
            throw new DomainException('Este requisito não admite dispensa.', 'requirement_not_waivable');
        }

        DB::transaction(function () use ($project, $data, $request): void {
            $project->waivers()->where('requirement_code', $data['requirement_code'])->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $project->waivers()->create([...$data, 'authorized_by' => $request->user()->id]);

            if ($project->process) {
                $this->timeline->record($project->process, 'REQUIREMENT_WAIVED', "Requisito dispensado: {$data['requirement_code']}", $data['reason']);
            }
        });

        return ProjectResource::make($project->refresh()->load(self::RELATIONS));
    }

    private function assertEditable(SolarProject $project): void
    {
        $process = $project->process;
        if ($process && (! $process->isActive() || ! $process->stage->allowsProjectEdit())) {
            throw new DomainException(
                "O projeto não pode ser alterado na etapa \"{$process->stage->label()}\". Use a versão congelada como referência.",
                'project_locked',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(SolarProject $project, array $data, int $userId): void
    {
        $project->equipment()->sync(collect($data['equipment'])->mapWithKeys(fn ($e) => [
            $e['id'] => ['quantity' => $e['quantity'], 'tenant_id' => $project->tenant_id],
        ])->all());

        $project->responsibilities()->delete();
        foreach ($data['responsibilities'] as $purpose => $resp) {
            $project->responsibilities()->create([
                'technical_responsible_id' => $resp['id'],
                'purpose' => $purpose,
                'art_number' => $resp['art_number'],
                'created_by' => $userId,
            ]);
        }

        $project->compensationUnits()->delete();
        foreach ($data['compensation_units'] as $unit) {
            $project->compensationUnits()->create($unit);
        }

        if ($data['initial_protocol'] !== null) {
            $request = $project->serviceRequest ?? new ServiceRequest;
            $request->fill([
                'consumer_unit_id' => $data['unit']->id,
                'distributor_id' => $data['unit']->distributor_id,
                'protocol_number' => $data['initial_protocol'],
                'opened_at' => $request->opened_at ?? now(),
                'created_by' => $request->created_by ?? $userId,
            ])->save();
            $project->forceFill(['service_request_id' => $request->id])->save();
        } elseif ($project->service_request_id) {
            $project->forceFill(['service_request_id' => null])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private function clientRequestToConvert(Request $request, array $data): ?\App\Domain\Projects\Models\ClientRequest
    {
        $uuid = $request->validate(['client_request_id' => ['sometimes', 'nullable', 'uuid']])['client_request_id'] ?? null;
        if (! $uuid) {
            return null;
        }

        $clientRequest = \App\Domain\Projects\Models\ClientRequest::query()->with('technicalResponsible')->where('uuid', $uuid)->firstOrFail();
        if (! $clientRequest->status->isOpen()) {
            throw new DomainException('Esta solicitação já foi encerrada.', 'request_closed');
        }
        if ($clientRequest->consumer_unit_id !== $data['unit']->id) {
            throw new DomainException('A UC do projeto deve ser a mesma da solicitação do cliente.', 'request_unit_mismatch');
        }

        return $clientRequest;
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'consumer_unit_id' => ['required', 'uuid'],
            'initial_protocol' => ['nullable', 'string', 'max:60'],
            'source_type' => ['sometimes', Rule::in(['SOLAR'])],
            'has_battery' => ['sometimes', 'boolean'],
            'storage_energy_kwh' => ['nullable', 'required_if:has_battery,true', 'numeric', 'min:0', 'max:100000'],
            'has_dispatch_controller' => ['sometimes', 'boolean'],
            'declared_dispatchable' => ['sometimes', 'boolean'],
            'has_coupling_transformer' => ['sometimes', 'boolean'],
            'estimated_generation_kwh_month' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'compensation_mode' => ['required', Rule::enum(CompensationMode::class)],
            'compensation_method' => ['nullable', Rule::in(['PERCENTAGE', 'PRIORITY'])],
            'compensation_units' => ['array', 'max:50'],
            'compensation_units.*.consumer_unit_id' => ['required', 'uuid', 'distinct'],
            'compensation_units.*.percentage' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'compensation_units.*.priority' => ['nullable', 'integer', 'min:1', 'max:999'],
            'project_rt' => ['nullable', 'array'],
            'project_rt.id' => ['required_with:project_rt', 'uuid'],
            'project_rt.art_number' => ['nullable', 'string', 'max:40'],
            'execution_rt' => ['nullable', 'array'],
            'execution_rt.id' => ['required_with:execution_rt', 'uuid'],
            'execution_rt.art_number' => ['nullable', 'string', 'max:40'],
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

        $responsibilities = [];
        foreach ([ResponsibilityPurpose::Project->value => 'project_rt', ResponsibilityPurpose::Execution->value => 'execution_rt'] as $purpose => $key) {
            if (empty($data[$key]['id'])) {
                continue;
            }
            $rt = TechnicalResponsible::query()->where('uuid', $data[$key]['id'])->where('active', true)->first();
            if (! $rt) {
                throw new DomainException('Responsável técnico inválido ou inativo.', 'responsible_invalid');
            }
            $responsibilities[$purpose] = ['id' => $rt->id, 'art_number' => $data[$key]['art_number'] ?? null];
        }

        $mode = CompensationMode::from($data['compensation_mode']);
        $units = [];
        if ($mode->allowsAllocation()) {
            $unitIds = ConsumerUnit::query()->whereIn('uuid', collect($data['compensation_units'] ?? [])->pluck('consumer_unit_id'))->pluck('id', 'uuid');
            foreach ($data['compensation_units'] ?? [] as $u) {
                if (! $unitIds->has($u['consumer_unit_id'])) {
                    throw new DomainException('Unidade beneficiária inválida.', 'compensation_unit_invalid');
                }
                $units[] = [
                    'consumer_unit_id' => (int) $unitIds->get($u['consumer_unit_id']),
                    'percentage' => $u['percentage'] ?? null,
                    'priority' => $u['priority'] ?? null,
                ];
            }
            if (($data['compensation_method'] ?? null) === 'PERCENTAGE' && $units !== []) {
                $sum = round(array_sum(array_map(fn ($u) => (float) $u['percentage'], $units)), 2);
                if ($sum > 100) {
                    throw new DomainException("A soma dos percentuais de rateio ({$sum}%) passa de 100%.", 'allocation_over_100');
                }
            }
        }

        $hasBattery = (bool) ($data['has_battery'] ?? false);

        return [
            'unit' => $unit,
            'initial_protocol' => filled($data['initial_protocol'] ?? null) ? trim($data['initial_protocol']) : null,
            'equipment' => $equipment,
            'responsibilities' => $responsibilities,
            'compensation_units' => $units,
            'attributes' => [
                'client_id' => $client->id,
                'consumer_unit_id' => $unit->id,
                'source_type' => $data['source_type'] ?? 'SOLAR',
                'has_battery' => $hasBattery,
                'storage_energy_kwh' => $hasBattery ? ($data['storage_energy_kwh'] ?? null) : null,
                'has_dispatch_controller' => (bool) ($data['has_dispatch_controller'] ?? false),
                'declared_dispatchable' => (bool) ($data['declared_dispatchable'] ?? false),
                'has_coupling_transformer' => (bool) ($data['has_coupling_transformer'] ?? false),
                'estimated_generation_kwh_month' => $data['estimated_generation_kwh_month'] ?? null,
                'compensation_mode' => $mode,
                'compensation_method' => $mode->allowsAllocation() ? ($data['compensation_method'] ?? null) : null,
                'notes' => $data['notes'] ?? null,
            ],
        ];
        $project->assertEditable();
    }
}
