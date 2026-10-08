<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Distributors\IntegrationService;
use App\Domain\Distributors\Models\ConnectionBudget;
use App\Domain\Distributors\Models\ExternalPendingItem;
use App\Domain\Distributors\Models\ExternalSubmission;
use App\Domain\Distributors\Models\Inspection;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\ProcessPendency;
use App\Domain\Homologations\ProcessWorkflow;
use App\Domain\Homologations\WorkflowDefinition;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExternalTrackingController extends Controller
{
    public function __construct(private readonly IntegrationService $integration) {}

    public function show(HomologationProcess $process): JsonResponse
    {
        $this->authorize('homologations.view');
        $external = $process->externalProcess;

        return response()->json(['data' => [
            'external' => $external ? ['id' => $external->uuid, 'protocol_number' => $external->external_protocol, 'status' => $external->external_status, 'portal_url' => $external->external_url, 'last_synced_at' => $external->last_synced_at?->toIso8601String()] : null,
            'submissions' => $process->submissions()->with(['version', 'receiptDocument'])->get()->map(fn ($s) => $this->submissionData($s)),
            'events' => $process->integrationEvents()->get()->map(fn ($e) => ['id' => $e->uuid, 'direction' => $e->direction, 'type' => $e->event_type, 'success' => $e->success, 'response' => $e->response_payload_json, 'request_hash' => $e->request_hash, 'occurred_at' => $e->occurred_at->toIso8601String()]),
            'pending_items' => $external?->pendingItems()->with('responseDocument')->get()->map(fn ($p) => ['id' => $p->uuid, 'code' => $p->code, 'description' => $p->description, 'status' => $p->status, 'due_at' => $p->due_at?->toIso8601String(), 'response_document_id' => $p->responseDocument?->uuid]) ?? [],
            'budgets' => $external?->budgets()->with('document')->get()->map(fn ($b) => ['id' => $b->uuid, 'issued_at' => $b->issued_at->toDateString(), 'expires_at' => $b->expires_at?->toDateString(), 'amount' => (float) $b->amount, 'works_required' => $b->works_required, 'document_id' => $b->document->uuid]) ?? [],
            'inspections' => $external?->inspections()->with('report')->get()->map(fn ($i) => ['id' => $i->uuid, 'status' => $i->status, 'requested_at' => $i->requested_at->toIso8601String(), 'scheduled_at' => $i->scheduled_at?->toIso8601String(), 'performed_at' => $i->performed_at?->toIso8601String(), 'connection_approved_at' => $i->connection_approved_at?->toIso8601String(), 'report_document_id' => $i->report?->uuid]) ?? [],
            'assignments' => $process->assignments()->with('user')->get()->map(fn ($a) => ['id' => $a->uuid, 'user_id' => $a->user->uuid, 'name' => $a->user->name, 'role' => $a->role, 'active' => $a->active, 'assigned_at' => $a->assigned_at?->toIso8601String(), 'revoked_at' => $a->revoked_at?->toIso8601String()]),
            'stage_history' => $process->stageHistory()->with(['stage', 'actor'])->get()->map(fn ($h) => ['stage' => $h->stage->name, 'entered_at' => $h->entered_at->toIso8601String(), 'left_at' => $h->left_at?->toIso8601String(), 'actor' => $h->actor?->name, 'notes' => $h->notes]),
        ]]);
    }

    public function prepare(Request $request, HomologationProcess $process): JsonResponse
    {
        $this->authorize('homologations.manage');
        $data = $request->validate(['kind' => ['required', Rule::in(['initial', 'correction', 'inspection'])], 'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'change_reason' => ['required', 'string', 'min:3', 'max:2000'], 'pending_item_id' => ['required_if:kind,correction', 'nullable', 'uuid']]);

        return response()->json(['data' => $this->submissionData($this->integration->prepare($process, $request->user(), $data))], 201);
    }

    public function confirm(Request $request, HomologationProcess $process, ExternalSubmission $submission): JsonResponse
    {
        $this->authorize('homologations.manage');
        abort_unless($submission->homologation_process_id === $process->id, 404);
        $data = $request->validate(['protocol_number' => ['required', 'string', 'max:80'], 'external_receipt' => ['required', 'string', 'min:3', 'max:255'], 'receipt_document_id' => ['required', 'uuid']]);

        return response()->json(['data' => $this->submissionData($this->integration->confirm($process, $submission, $request->user(), $data))]);
    }

    public function fail(Request $request, HomologationProcess $process, ExternalSubmission $submission): JsonResponse
    {
        $this->authorize('homologations.manage');
        abort_unless($submission->homologation_process_id === $process->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $this->integration->fail($process, $submission, $request->user(), $data['reason']);

        return $this->show($process->refresh());
    }

    public function manifest(HomologationProcess $process, ExternalSubmission $submission): JsonResponse
    {
        $this->authorize('documents.view');
        abort_unless($submission->homologation_process_id === $process->id, 404);

        return response()->json(['data' => ['submission' => $this->submissionData($submission), 'payload' => $submission->payload_json]]);
    }

    public function dossier(HomologationProcess $process, ExternalSubmission $submission): BinaryFileResponse
    {
        $this->authorize('documents.view');
        abort_unless($submission->homologation_process_id === $process->id, 404);
        $path = tempnam(sys_get_temp_dir(), 'homologa-dossier-');
        $zip = new \ZipArchive;
        try {
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new DomainException('Não foi possível preparar o arquivo de exportação.', 'export_failed', 500);
            }
            $zip->addFromString('manifesto.json', json_encode(['submission' => $this->submissionData($submission), 'payload' => $submission->payload_json], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            foreach ($submission->version->documents as $document) {
                if (! $document->verifyHash()) {
                    throw new DomainException('Arquivo ausente ou divergente no dossiê congelado: '.$document->original_name, 'document_integrity_failed', 409);
                }
                $zip->addFile(Storage::disk('local')->path($document->storage_path), 'documentos/'.$document->document_type.'-v'.$document->version.'-'.$document->uuid.'.'.pathinfo($document->storage_path, PATHINFO_EXTENSION));
            }
            if (! $zip->close()) {
                throw new DomainException('Não foi possível concluir a exportação.', 'export_failed', 500);
            }

            return response()->download($path, $process->code.'-v'.$submission->version->version.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        } catch (\Throwable $error) {
            if ($zip->status === \ZipArchive::ER_OK) {
                try {
                    $zip->close();
                } catch (\Throwable) {
                }
            } if (is_file($path)) {
                unlink($path);
            } throw $error;
        }
    }

    public function status(Request $request, HomologationProcess $process): JsonResponse
    {
        $this->authorize('homologations.manage');
        $data = $request->validate(['status' => ['required', 'string', 'max:120'], 'portal_url' => ['nullable', 'url:http,https', 'max:500']]);
        DB::transaction(function () use ($process, $request, $data) {
            $this->integration->external($process)->update(['external_status' => $data['status'], 'external_url' => $data['portal_url'] ?? null, 'last_synced_at' => now()]);
            $this->integration->event($process, $request->user(), 'IN', 'assisted_status', true, $data);
        });

        return $this->show($process->refresh());
    }

    public function pending(Request $request, HomologationProcess $process): JsonResponse
    {
        $this->authorize('homologations.manage');
        $data = $request->validate(['code' => ['nullable', 'string', 'max:80'], 'description' => ['required', 'string', 'min:3', 'max:5000'], 'due_at' => ['nullable', 'date']]);
        DB::transaction(function () use ($process, $request, $data) {
            $process = HomologationProcess::whereKey($process->id)->lockForUpdate()->firstOrFail();
            if ($process->status->isTerminal()) {
                throw new DomainException('Processo encerrado não aceita novas pendências.', 'process_closed');
            }
            $external = $this->integration->external($process);
            $pending = ExternalPendingItem::create([...$data, 'external_process_id' => $external->id]);
            ProcessPendency::create(['homologation_process_id' => $process->id, 'external_pending_item_id' => $pending->id, 'origin' => 'distribuidora', 'title' => mb_substr($data['description'], 0, 180), 'description' => $data['description'], 'status' => 'aberta', 'due_date' => $data['due_at'] ?? null, 'created_by' => $request->user()->id]);
            if ($process->status->value !== 'pendencia_distribuidora') {
                $target = app(WorkflowDefinition::class)->transitions($process)->first(fn ($s) => $s->stage_type === 'pendencia_distribuidora');
                if (! $target) {
                    throw new DomainException('A etapa atual não permite registrar uma pendência da distribuidora.', 'invalid_transition');
                }
                app(ProcessWorkflow::class)->transition($process, $target->code, $request->user(), ['reason' => $data['description'], 'external_pending_item_id' => $pending->id]);
            }
            $this->integration->event($process, $request->user(), 'IN', 'external_pending', true, ['code' => $data['code'] ?? null, 'description' => $data['description']]);
        });

        return $this->show($process->refresh());
    }

    public function response(Request $request, HomologationProcess $process, ExternalPendingItem $pending): JsonResponse
    {
        $this->authorize('homologations.manage');
        abort_unless($process->externalProcess?->id === $pending->external_process_id, 404);
        $data = $request->validate(['response_document_id' => ['required', 'uuid']]);
        if ($pending->status !== 'aberta') {
            throw new DomainException('Esta pendência já foi respondida.', 'pending_closed', 409);
        }
        $document = $this->integration->document($process, $data['response_document_id']);
        if (! $document->isValid()) {
            throw new DomainException('Aprove o documento antes de vinculá-lo à resposta.', 'response_not_approved');
        }
        $pending->update(['response_document_id' => $document->id]);

        return $this->show($process->refresh());
    }

    public function budget(Request $request, HomologationProcess $process): JsonResponse
    {
        $this->authorize('homologations.manage');
        $data = $request->validate(['issued_at' => ['required', 'date', 'before_or_equal:today'], 'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'], 'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'], 'works_required' => ['required', 'boolean'], 'document_id' => ['required', 'uuid']]);
        $document = $this->integration->document($process, $data['document_id'], 'orcamento_conexao');
        ConnectionBudget::create([...$data, 'external_process_id' => $this->integration->external($process)->id, 'document_id' => $document->id]);

        return $this->show($process->refresh());
    }

    public function inspection(Request $request, HomologationProcess $process, ?Inspection $inspection = null): JsonResponse
    {
        $this->authorize('homologations.manage');
        $data = $request->validate(['requested_at' => ['required', 'date', 'before_or_equal:now'], 'scheduled_at' => ['nullable', 'date', 'after_or_equal:requested_at'], 'performed_at' => ['nullable', 'date', 'after_or_equal:requested_at', 'before_or_equal:now'],
            'status' => ['required', Rule::in(['solicitada', 'agendada', 'realizada', 'aprovada', 'reprovada'])], 'report_document_id' => ['required_if:status,aprovada,reprovada,realizada', 'nullable', 'uuid'],
            'connection_approved_at' => ['required_if:status,aprovada', 'nullable', 'date', 'after_or_equal:performed_at', 'before_or_equal:now'], 'submission_id' => ['required', 'uuid']]);
        $external = $this->integration->external($process);
        if ($inspection) {
            abort_unless($inspection->external_process_id === $external->id, 404);
        }
        $submission = $process->submissions()->where('uuid', $data['submission_id'])->where('kind', 'inspection')->where('status', 'sent')->firstOrFail();
        if (in_array($data['status'], ['realizada', 'aprovada', 'reprovada'], true) && empty($data['performed_at'])) {
            throw new DomainException('Informe a data de realização da vistoria.', 'inspection_date_required');
        }
        if ($data['status'] === 'agendada' && empty($data['scheduled_at'])) {
            throw new DomainException('Informe a data agendada.', 'inspection_date_required');
        }
        $document = ! empty($data['report_document_id']) ? $this->integration->document($process, $data['report_document_id'], 'relatorio_vistoria') : null;
        if ($data['status'] === 'aprovada' && ! $document?->isValid()) {
            throw new DomainException('Aprove o relatório da vistoria.', 'inspection_report_required');
        }
        $attributes = [...$data, 'external_process_id' => $external->id, 'submission_id' => $submission->id, 'report_document_id' => $document?->id];
        DB::transaction(function () use ($inspection, $attributes, $process, $request) {
            $inspection ? $inspection->update($attributes) : Inspection::create($attributes);
            $this->integration->event($process, $request->user(), 'IN', 'assisted_inspection', true, ['status' => $attributes['status'], 'performed_at' => $attributes['performed_at'] ?? null]);
        });

        return $this->show($process->refresh());
    }

    /** @return array<string, mixed> */
    private function submissionData(ExternalSubmission $s): array
    {
        return ['id' => $s->uuid, 'kind' => $s->kind, 'idempotency_key' => $s->idempotency_key, 'request_hash' => $s->request_hash, 'status' => $s->status,
            'version_id' => $s->version->uuid, 'version' => $s->version->version, 'change_reason' => $s->version->change_reason, 'external_receipt' => $s->external_receipt, 'receipt_document_id' => $s->receiptDocument?->uuid, 'submitted_at' => $s->submitted_at?->toIso8601String()];
    }
}
