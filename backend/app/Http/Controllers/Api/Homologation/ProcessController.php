<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Homologations\Enums\ConnectionEventType;
use App\Domain\Homologations\Enums\NetworkWorkStatus;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Enums\WorkflowStage;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Inspection;
use App\Domain\Homologations\Models\ProcessInteraction;
use App\Domain\Homologations\ProcessWorkflow;
use App\Domain\Homologations\TimelineRecorder;
use App\Domain\Rules\Enums\RequirementPhase;
use App\Domain\Rules\RequirementEngine;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\ProcessResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class ProcessController extends Controller
{
    public function __construct(
        private readonly ProcessWorkflow $workflow,
        private readonly RequirementEngine $requirements,
        private readonly TimelineRecorder $timeline,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('homologations.view');

        $filters = $request->validate([
            'stage' => ['sometimes', 'nullable', Rule::enum(WorkflowStage::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(ProcessStatus::class)],
            'distributor' => ['sometimes', 'nullable', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'board' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:200'],
        ]);

        $query = HomologationProcess::query()
            ->with(['distributor', 'assignee', 'project.client', 'project.consumerUnit', 'deadlines'])
            ->withCount('openPendencies')
            ->when($filters['stage'] ?? null, fn ($q, string $s) => $q->where('stage', $s))
            ->when($filters['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($filters['distributor'] ?? null, fn ($q, string $uuid) => $q->whereHas('distributor', fn ($d) => $d->where('uuid', $uuid)))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$s}%")
                ->orWhere('protocol_number', 'ilike', "%{$s}%")
                ->orWhereHas('project.client', fn ($c) => $c->where('name', 'ilike', "%{$s}%"))
                ->orWhereHas('project.consumerUnit', fn ($u) => $u->where('number', 'like', "%{$s}%"))));

        if ($request->boolean('board')) {
            return ProcessResource::collection($query->where('status', ProcessStatus::Active->value)->orderBy('stage_changed_at')->limit(500)->get());
        }

        return ProcessResource::collection($query->latest('id')->paginate($filters['per_page'] ?? 20));
    }

    public function show(HomologationProcess $process): ProcessResource
    {
        $this->authorize('homologations.view');

        return $this->detail($process);
    }

    public function update(Request $request, HomologationProcess $process): ProcessResource
    {
        $this->authorize('homologations.manage');

        $data = $request->validate([
            'assigned_user_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (array_key_exists('assigned_user_id', $data)) {
            $process->assigned_user_id = $data['assigned_user_id']
                ? User::query()->where('uuid', $data['assigned_user_id'])->firstOrFail()->id
                : null;
            $process->save();
        }

        return $this->detail($process);
    }

    /**
     * Endpoint único de ações do fluxo. O domínio valida etapa e requisitos.
     */
    public function action(Request $request, HomologationProcess $process, string $action): ProcessResource
    {
        $this->authorize('homologations.manage');
        $user = $request->user();

        match ($action) {
            'submit' => $this->workflow->submit($process, $user,
                $request->validate(['protocol_number' => ['required', 'string', 'max:80']])['protocol_number']),
            'register-correction' => (function () use ($request, $process, $user) {
                $d = $request->validate([
                    'items' => ['required', 'array', 'min:1', 'max:30'],
                    'items.*' => ['required', 'string', 'max:200'],
                    'notes' => ['nullable', 'string', 'max:5000'],
                ]);
                $this->workflow->registerCorrection($process, $user, $d['items'], $d['notes'] ?? null);
            })(),
            'approve-access' => (function () use ($request, $process, $user) {
                $d = $request->validate([
                    'network_work_status' => ['required', Rule::enum(NetworkWorkStatus::class)],
                    'notes' => ['nullable', 'string', 'max:2000'],
                ]);
                $this->workflow->approveAccess($process, $user, NetworkWorkStatus::from($d['network_work_status']), $d['notes'] ?? null);
            })(),
            'network-work' => (function () use ($request, $process, $user) {
                $d = $request->validate([
                    'network_work_status' => ['required', Rule::enum(NetworkWorkStatus::class)],
                    'notes' => ['nullable', 'string', 'max:2000'],
                ]);
                $this->workflow->updateNetworkWork($process, $user, NetworkWorkStatus::from($d['network_work_status']), $d['notes'] ?? null);
            })(),
            'execution' => $this->workflow->reportExecution($process, $user, $request->validate([
                'started_at' => ['nullable', 'date', 'before_or_equal:today'],
                'completed_at' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:started_at'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ])),
            'request-inspection' => $this->workflow->requestInspection($process, $user,
                $request->validate(['scheduled_for' => ['nullable', 'date', 'after_or_equal:today']])['scheduled_for'] ?? null),
            'connection-event' => $this->workflow->recordConnectionEvent($process, $user, $request->validate([
                'type' => ['required', Rule::enum(ConnectionEventType::class)],
                'occurred_at' => ['required', 'date', 'before_or_equal:now'],
                'meter_number' => ['nullable', 'string', 'max:60'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ])),
            'complete' => $this->workflow->complete($process, $user),
            'cancel' => $this->workflow->cancel($process, $user,
                $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']])['reason']),
            default => throw new DomainException('Ação desconhecida.', 'unknown_action', 404),
        };

        return $this->detail($process->refresh());
    }

    public function scheduleInspection(Request $request, Inspection $inspection): ProcessResource
    {
        $this->authorize('homologations.manage');
        $data = $request->validate(['scheduled_for' => ['required', 'date', 'after_or_equal:today']]);
        $this->workflow->scheduleInspection($inspection, $request->user(), $data['scheduled_for']);

        return $this->detail($inspection->process->refresh());
    }

    public function inspectionResult(Request $request, Inspection $inspection): ProcessResource
    {
        $this->authorize('homologations.manage');
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'notes' => ['nullable', 'required_if:approved,false', 'string', 'max:5000'],
        ], ['notes.required_if' => 'Descreva o motivo da reprovação.']);

        $this->workflow->recordInspectionResult($inspection, $request->user(), (bool) $data['approved'], $data['notes'] ?? null);

        return $this->detail($inspection->process->refresh());
    }

    public function storeInteraction(Request $request, HomologationProcess $process): JsonResponse
    {
        $this->authorize('homologations.manage');

        $data = $request->validate([
            'type' => ['required', Rule::in(ProcessInteraction::TYPES)],
            'channel' => ['required', Rule::in(ProcessInteraction::CHANNELS)],
            'description' => ['required', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        ProcessInteraction::create([
            ...$data,
            'homologation_process_id' => $process->id,
            'user_id' => $request->user()->id,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        $this->timeline->record($process, 'INTERACTION', 'Interação registrada', $data['description']);

        return response()->json(['message' => 'Interação registrada.'], 201);
    }

    public function stages(): JsonResponse
    {
        return response()->json(['data' => [
            'stages' => array_map(fn (WorkflowStage $s) => ['value' => $s->value, 'label' => $s->label()], WorkflowStage::cases()),
            'network_work' => array_map(fn (NetworkWorkStatus $s) => ['value' => $s->value, 'label' => $s->label()], NetworkWorkStatus::cases()),
            'connection_events' => array_map(fn (ConnectionEventType $s) => ['value' => $s->value, 'label' => $s->label()], ConnectionEventType::cases()),
        ]]);
    }

    private function detail(HomologationProcess $process): ProcessResource
    {
        $process->load([
            'distributor', 'assignee', 'currentVersion', 'deadlines', 'execution',
            'inspections', 'connectionEvents', 'timeline.user', 'pendencies.author', 'pendencies.resolver',
            'interactions.user',
            'project' => fn ($q) => $q->with([
                'client', 'consumerUnit.distributor', 'serviceRequest', 'equipment',
                'responsibilities.responsible', 'compensationUnits.consumerUnit', 'fastTrackAcceptances', 'waivers', 'process',
            ]),
        ])->loadCount('openPendencies');

        $phase = match ($process->stage) {
            WorkflowStage::Execution, WorkflowStage::Inspection => RequirementPhase::InspectionRequest,
            WorkflowStage::Connection => RequirementPhase::Completion,
            default => RequirementPhase::Submission,
        };

        $checklist = $this->requirements->evaluate($process->project, $phase, $process);
        unset($checklist['facts']);

        $documents = $this->requirements->currentDocuments($process->project, $process)
            ->load(['uploader', 'reviewer'])
            ->map(fn ($d) => (new \App\Http\Resources\Homologation\DocumentResource($d))->toArray(request()))
            ->values();

        return ProcessResource::make($process)->with_([
            'actions' => $this->workflow->availableActions($process),
            'checklist' => $checklist,
            'documents' => $documents,
        ]);
    }
}
