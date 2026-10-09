<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Homologations\Enums\ConnectionEventType;
use App\Domain\Homologations\Enums\NetworkWorkStatus;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Enums\WorkflowStage as ProcessStage;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Inspection;
use App\Domain\Homologations\Models\ProcessInteraction;
use App\Domain\Homologations\Models\WorkflowStage;
use App\Domain\Homologations\ProcessActionWorkflow;
use App\Domain\Homologations\ProcessWorkflow;
use App\Domain\Homologations\WorkflowDefinition;
use App\Domain\Rules\Enums\RequirementPhase;
use App\Domain\Rules\RequirementEngine;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\ProcessResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ProcessController extends Controller
{
    public function __construct(private readonly ProcessWorkflow $workflow, private readonly ProcessActionWorkflow $actions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('homologations.view');

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:60'],
            'scope' => ['sometimes', Rule::in(['active', 'closed', 'all'])],
            'distributor' => ['sometimes', 'nullable', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'board' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:200'],
        ]);

        $query = HomologationProcess::query()
            ->with(['distributor', 'assignee', 'project.client', 'project.consumerUnit', 'currentStage', 'deadlines'])
            ->withCount('openPendencies')
            ->when(($filters['scope'] ?? 'all') === 'active', fn ($q) => $q->whereNotIn('status', [ProcessStatus::Conectado->value, ProcessStatus::Cancelado->value]))
            ->when(($filters['scope'] ?? 'all') === 'closed', fn ($q) => $q->whereIn('status', [ProcessStatus::Conectado->value, ProcessStatus::Cancelado->value]))
            ->when($filters['status'] ?? null, fn ($q, string $s) => $q->whereHas('currentStage', fn ($stage) => $stage->where('code', $s)))
            ->when($filters['distributor'] ?? null, fn ($q, string $uuid) => $q->whereHas('distributor', fn ($d) => $d->where('uuid', $uuid)))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$s}%")
                ->orWhere('protocol_number', 'ilike', "%{$s}%")
                ->orWhereHas('project.client', fn ($c) => $c->where('name', 'ilike', "%{$s}%"))
                ->orWhereHas('project.consumerUnit', fn ($u) => $u->where('number', 'like', "%{$s}%"))));

        if ($request->boolean('board')) {
            $query->whereNotIn('status', [ProcessStatus::Conectado->value, ProcessStatus::Cancelado->value])
                ->orderBy('stage_changed_at');

            return ProcessResource::collection($query->limit(500)->get());
        }

        return ProcessResource::collection($query->latest('id')->paginate($filters['per_page'] ?? 20));
    }

    public function show(HomologationProcess $process): ProcessResource
    {
        $this->authorize('homologations.view');

        $process->load([
            'distributor', 'assignee', 'history.user', 'interactions.user', 'currentStage',
            'pendencies.author', 'pendencies.resolver',
            'currentDocuments.uploader', 'currentDocuments.reviewer',
            'project.client', 'project.consumerUnit.distributor', 'project.technicalResponsible', 'project.equipment',
            'currentVersion', 'deadlines', 'execution', 'inspections', 'connectionEvents', 'timeline.user',
            'project.responsibilities.responsible', 'project.compensationUnits.consumerUnit', 'project.fastTrackAcceptances', 'project.waivers',
        ])->loadCount('openPendencies');

        $phase = match ($process->stage) {
            ProcessStage::Execution, ProcessStage::Inspection => RequirementPhase::InspectionRequest,
            ProcessStage::Connection => RequirementPhase::Completion,
            default => RequirementPhase::Submission,
        };

        return ProcessResource::make($process)->withReadiness($this->workflow->readinessIssues($process))
            ->additional(['data' => ['actions' => $this->actions->availableActions($process), 'phase_checklist' => collect(app(RequirementEngine::class)->evaluate($process->project, $phase, $process))->except('facts')->all()]]);
    }

    public function update(Request $request, HomologationProcess $process): ProcessResource
    {
        $this->authorize('homologations.manage');

        $data = $request->validate([
            'assigned_user_id' => ['sometimes', 'nullable', 'uuid'],
            'priority' => ['sometimes', Rule::in(['baixa', 'normal', 'alta', 'urgente'])],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'protocol_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'justification' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('assigned_user_id', $data)) {
            $process->assigned_user_id = $data['assigned_user_id']
                ? User::query()->where('uuid', $data['assigned_user_id'])->firstOrFail()->id
                : null;
        }
        if (array_key_exists('due_date', $data)) {
            $process->due_date = $data['due_date'];
        }

        // Protocolo já registrado só pode ser corrigido com permissão específica e justificativa (auditada).
        if (array_key_exists('protocol_number', $data) && $data['protocol_number'] !== $process->protocol_number) {
            if ($process->protocol_number !== null) {
                abort_unless($request->user()->hasPermission(PermissionKey::ProtocolOverride), 403, 'Sem permissão para corrigir protocolo.');
                $request->validate(['justification' => ['required', 'string', 'min:10']]);
            }
            if ($data['protocol_number']) {
                $this->workflow->assertProtocolAvailable($process, trim($data['protocol_number']));
            }
            $process->protocol_number = $data['protocol_number'] ? trim($data['protocol_number']) : null;

            ProcessInteraction::create([
                'homologation_process_id' => $process->id,
                'type' => 'nota',
                'channel' => 'portal',
                'description' => 'Protocolo alterado para '.($process->protocol_number ?? '(vazio)').
                    (! empty($data['justification']) ? '. Justificativa: '.$data['justification'] : '.'),
                'user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);
        }

        DB::transaction(function () use ($process, $data): void {
            if ($process->isDirty('assigned_user_id')) {
                $process->assignments()->where('active', true)->whereIn('role', ['responsavel', 'homologador'])->update(['active' => false, 'revoked_at' => now()]);
                if ($process->assigned_user_id) {
                    $process->assignments()->create(['user_id' => $process->assigned_user_id, 'role' => 'responsavel', 'active' => true, 'assigned_at' => now()]);
                }
            }
            if (isset($data['priority'])) {
                $process->priority = $data['priority'];
            }
            $process->save();
        });

        return $this->show($process);
    }

    public function transition(Request $request, HomologationProcess $process): ProcessResource
    {
        $this->authorize('homologations.manage');

        $data = $request->validate([
            'status' => ['required', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'protocol_number' => ['nullable', 'string', 'max:80'],
        ]);

        $this->workflow->transition($process, $data['status'], $request->user(), $data);

        return $this->show($process->refresh());
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

        return response()->json(['message' => 'Interação registrada.'], 201);
    }

    /**
     * Metadados para a UI listar situações e transições sem duplicar regras.
     */
    public function statuses(): JsonResponse
    {
        $this->authorize('homologations.view');
        app(WorkflowDefinition::class)->provision();

        return response()->json([
            'data' => WorkflowStage::where('active', true)->orderBy('order')->get()->map(fn ($s) => [
                'value' => $s->code, 'label' => $s->name, 'stage_type' => $s->stage_type,
                'on_board' => ! in_array($s->stage_type, ['cancelado', 'reprovado']),
                'terminal' => in_array($s->stage_type, ['conectado', 'cancelado']), 'transitions' => $s->next_stage_rule_json['next'] ?? [],
            ]),
        ]);
    }

    public function assignmentUsers(): JsonResponse
    {
        $this->authorize('homologations.manage');

        return response()->json(['data' => User::where('active', true)->orderBy('name')->get()->map(fn ($user) => ['id' => $user->uuid, 'name' => $user->name])]);
    }

    public function action(Request $request, HomologationProcess $process, string $action): ProcessResource
    {
        $this->authorize('homologations.manage');
        $user = $request->user();

        match ($action) {
            'submit' => $this->actions->submit($process, $user,
                $request->validate(['protocol_number' => ['required', 'string', 'max:80'], 'receipt_document_id' => ['required', 'uuid'], 'external_receipt' => ['required', 'string', 'min:3', 'max:255']])['protocol_number'], $request->input('receipt_document_id'), $request->input('external_receipt')),
            'register-correction' => (function () use ($request, $process, $user) {
                $d = $request->validate([
                    'items' => ['required', 'array', 'min:1', 'max:30'],
                    'items.*' => ['required', 'string', 'max:200'],
                    'notes' => ['nullable', 'string', 'max:5000'],
                ]);
                $this->actions->registerCorrection($process, $user, $d['items'], $d['notes'] ?? null);
            })(),
            'approve-access' => (function () use ($request, $process, $user) {
                $d = $request->validate([
                    'network_work_status' => ['required', Rule::enum(NetworkWorkStatus::class)],
                    'notes' => ['nullable', 'string', 'max:2000'],
                ]);
                $this->actions->approveAccess($process, $user, NetworkWorkStatus::from($d['network_work_status']), $d['notes'] ?? null);
            })(),
            'network-work' => (function () use ($request, $process, $user) {
                $d = $request->validate([
                    'network_work_status' => ['required', Rule::enum(NetworkWorkStatus::class)],
                    'notes' => ['nullable', 'string', 'max:2000'],
                ]);
                $this->actions->updateNetworkWork($process, $user, NetworkWorkStatus::from($d['network_work_status']), $d['notes'] ?? null);
            })(),
            'execution' => $this->actions->reportExecution($process, $user, $request->validate([
                'started_at' => ['nullable', 'date', 'before_or_equal:today'],
                'completed_at' => ['required', 'date', 'before_or_equal:today', Rule::when($request->filled('started_at'), ['after_or_equal:started_at'])],
                'notes' => ['nullable', 'string', 'max:5000'],
            ])),
            'request-inspection' => $this->actions->requestInspection($process, $user,
                $request->validate(['scheduled_for' => ['nullable', 'date', 'after_or_equal:today']])['scheduled_for'] ?? null),
            'connection-event' => $this->actions->recordConnectionEvent($process, $user, $request->validate([
                'type' => ['required', Rule::enum(ConnectionEventType::class)],
                'occurred_at' => ['required', 'date', 'before_or_equal:now'],
                'meter_number' => ['nullable', 'string', 'max:60'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ])),
            'complete' => $this->actions->complete($process, $user),
            'cancel' => $this->actions->cancel($process, $user,
                $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']])['reason']),
            default => throw new DomainException('Ação desconhecida.', 'unknown_action', 404),
        };

        return $this->show($process->refresh());
    }

    public function scheduleInspection(Request $request, Inspection $inspection): ProcessResource
    {
        $this->authorize('homologations.manage');
        $data = $request->validate(['scheduled_for' => ['required', 'date', 'after_or_equal:today']]);
        $this->actions->scheduleInspection($inspection, $request->user(), $data['scheduled_for']);

        return $this->show($inspection->process->refresh());
    }

    public function inspectionResult(Request $request, Inspection $inspection): ProcessResource
    {
        $this->authorize('homologations.manage');
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'report_document_id' => ['required', 'uuid'],
            'performed_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'required_if:approved,false', 'string', 'max:5000'],
        ], ['notes.required_if' => 'Descreva o motivo da reprovação.']);

        $this->actions->recordInspectionResult($inspection, $request->user(), (bool) $data['approved'], $data['notes'] ?? null, $data['report_document_id'], $data['performed_at']);

        return $this->show($inspection->process->refresh());
    }

    public function stages(): JsonResponse
    {
        return response()->json(['data' => [
            'stages' => array_map(fn (ProcessStage $s) => ['value' => $s->value, 'label' => $s->label()], ProcessStage::cases()),
            'network_work' => array_map(fn (NetworkWorkStatus $s) => ['value' => $s->value, 'label' => $s->label()], NetworkWorkStatus::cases()),
            'connection_events' => array_map(fn (ConnectionEventType $s) => ['value' => $s->value, 'label' => $s->label()], ConnectionEventType::cases()),
        ]]);
    }
}
