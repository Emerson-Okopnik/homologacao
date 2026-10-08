<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\ProcessInteraction;
use App\Domain\Homologations\Models\WorkflowStage;
use App\Domain\Homologations\ProcessWorkflow;
use App\Domain\Homologations\WorkflowDefinition;
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
    public function __construct(private readonly ProcessWorkflow $workflow) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('homologations.view');

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:60'],
            'distributor' => ['sometimes', 'nullable', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'board' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:200'],
        ]);

        $query = HomologationProcess::query()
            ->with(['distributor', 'assignee', 'project.client', 'project.consumerUnit', 'currentStage'])
            ->withCount('openPendencies')
            ->when($filters['status'] ?? null, fn ($q, string $s) => $q->whereHas('currentStage', fn ($stage) => $stage->where('code', $s)))
            ->when($filters['distributor'] ?? null, fn ($q, string $uuid) => $q->whereHas('distributor', fn ($d) => $d->where('uuid', $uuid)))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$s}%")
                ->orWhere('protocol_number', 'ilike', "%{$s}%")
                ->orWhereHas('project.client', fn ($c) => $c->where('name', 'ilike', "%{$s}%"))
                ->orWhereHas('project.consumerUnit', fn ($u) => $u->where('number', 'like', "%{$s}%"))));

        if ($request->boolean('board')) {
            $query->whereNotIn('status', [ProcessStatus::Cancelado->value, ProcessStatus::Reprovado->value])
                ->orderBy('status_changed_at');

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
        ])->loadCount('openPendencies');

        return ProcessResource::make($process)->withReadiness($this->workflow->readinessIssues($process));
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
     * Metadados para a UI montar colunas e transições sem duplicar regras.
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
}
