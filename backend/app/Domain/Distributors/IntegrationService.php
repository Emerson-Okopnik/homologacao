<?php

namespace App\Domain\Distributors;

use App\Domain\Audit\Redactor;
use App\Domain\Distributors\Models\ExternalPendingItem;
use App\Domain\Distributors\Models\ExternalProcess;
use App\Domain\Distributors\Models\ExternalSubmission;
use App\Domain\Distributors\Models\IntegrationEvent;
use App\Domain\Documents\DocumentTypes;
use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\ProcessWorkflow;
use App\Domain\Homologations\ValidationService;
use App\Domain\Homologations\WorkflowDefinition;
use App\Domain\Projects\ProjectVersionService;
use App\Domain\Rules\Enums\RequirementPhase;
use App\Domain\Rules\RequirementEngine;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

final class IntegrationService
{
    public function external(HomologationProcess $process): ExternalProcess
    {
        return ExternalProcess::firstOrCreate(['homologation_process_id' => $process->id]);
    }

    /** @param array<string, mixed> $data */
    public function prepare(HomologationProcess $process, User $actor, array $data): ExternalSubmission
    {
        return DB::transaction(function () use ($process, $actor, $data) {
            $process = HomologationProcess::query()->whereKey($process->id)->lockForUpdate()->firstOrFail();
            $existing = $process->submissions()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $pending = isset($data['pending_item_id']) ? ExternalPendingItem::where('uuid', $data['pending_item_id'])->firstOrFail() : null;
                if ($existing->kind !== $data['kind'] || $existing->response_to_pending_item_id !== $pending?->id || $existing->version->change_reason !== $data['change_reason']) {
                    throw new DomainException('Esta chave já identifica outro envio.', 'idempotency_conflict', 409);
                }

                return $existing;
            }
            $expected = ['initial' => ['pronto_para_envio'], 'correction' => ['pendencia_distribuidora'], 'inspection' => ['aprovado']];
            if (! in_array($process->status->value, $expected[$data['kind']], true)) {
                throw new DomainException('O processo não está na etapa adequada para este envio.', 'submission_stage_invalid', 409);
            }
            app(ValidationService::class)->ensureReady($process);
            $external = $this->external($process);
            $pending = null;
            if ($data['kind'] === 'correction') {
                $pending = ExternalPendingItem::query()->where('external_process_id', $external->id)->where('uuid', $data['pending_item_id'] ?? '')->where('status', 'aberta')->firstOrFail();
                if (! $pending->responseDocument?->isValid() || ! $pending->responseDocument->verifyHash()) {
                    throw new DomainException('Vincule um documento de resposta aprovado à pendência.', 'pending_response_required');
                }
            }
            $ruleChecklist = $data['rule_checklist'] ?? null;
            if ($process->project->classification !== null) {
                $phase = $data['kind'] === 'inspection' ? RequirementPhase::InspectionRequest : RequirementPhase::Submission;
                $ruleChecklist ??= app(RequirementEngine::class)->evaluateAndRecord($process->project, $phase, $process);
                if ($ruleChecklist['blocking']) {
                    throw new DomainException('Requisitos pendentes: '.implode('; ', $ruleChecklist['blocking']), 'requirements_pending');
                }
            }
            $version = app(ProjectVersionService::class)->freeze($process->project, $actor, $data['change_reason'], $process, $ruleChecklist);
            if ($pending && ! $version->documents()->whereKey($pending->response_document_id)->exists()) {
                throw new DomainException('Use a versão atual do documento de resposta no dossiê.', 'pending_response_outdated');
            }
            $payload = (new CelescGatewayAdapter)->buildPayload($process, $version, $data['kind']);
            if ($pending) {
                $payload['pending_item'] = ['id' => $pending->uuid, 'code' => $pending->code, 'response_document' => $pending->responseDocument->uuid, 'sha256' => $pending->responseDocument->sha256];
            }
            $submission = ExternalSubmission::create(['homologation_process_id' => $process->id, 'external_process_id' => $external->id, 'project_version_id' => $version->id,
                'kind' => $data['kind'], 'idempotency_key' => $data['idempotency_key'], 'request_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'payload_json' => $payload, 'status' => 'prepared', 'created_by' => $actor->id, 'response_to_pending_item_id' => $pending?->id]);
            $this->event($process, $actor, 'OUT', 'prepared', true, ['channel' => 'assisted', 'version' => $version->version], $submission);

            return $submission->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function confirm(HomologationProcess $process, ExternalSubmission $submission, User $actor, array $data): ExternalSubmission
    {
        return DB::transaction(function () use ($process, $submission, $actor, $data) {
            $process = HomologationProcess::query()->whereKey($process->id)->lockForUpdate()->firstOrFail();
            $submission = $process->submissions()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $receipt = $this->document($process, $data['receipt_document_id'], 'comprovante_envio');
            if ($submission->status === 'sent') {
                if ($submission->external_receipt !== $data['external_receipt'] || $submission->receipt_document_id !== $receipt->id || $process->protocol_number !== trim($data['protocol_number'])) {
                    throw new DomainException('Este envio já foi confirmado com outro comprovante.', 'idempotency_conflict', 409);
                }

                return $submission;
            }
            app(ValidationService::class)->ensureReady($process);
            foreach ($submission->version->documents as $document) {
                if (! $document->isValid() || ! $document->verifyHash()) {
                    throw new DomainException('Um documento da versão congelada foi invalidado ou está ausente. Prepare outro envio.', 'frozen_document_invalid', 409);
                }
            }
            if (! $receipt->isValid() || ! $receipt->verifyHash()) {
                throw new DomainException('Revise e aprove o comprovante de envio.', 'receipt_not_approved');
            }
            $protocol = trim($data['protocol_number']);
            app(ProcessWorkflow::class)->assertProtocolAvailable($process, $protocol);
            if ($process->protocol_number && $process->protocol_number !== $protocol) {
                throw new DomainException('Use o protocolo já vinculado ao processo.', 'protocol_mismatch', 409);
            }
            $submission->update(['status' => 'sent', 'submitted_at' => now(), 'external_receipt' => $data['external_receipt'], 'receipt_document_id' => $receipt->id]);
            $process->update(['project_version_id' => $submission->project_version_id]);
            $submission->externalProcess->update(['external_protocol' => $protocol, 'external_status' => 'enviado', 'last_synced_at' => now()]);
            if ($submission->response_to_pending_item_id) {
                $pending = ExternalPendingItem::query()->whereKey($submission->response_to_pending_item_id)->where('external_process_id', $submission->external_process_id)->lockForUpdate()->firstOrFail();
                if ($pending->responseDocument?->uuid !== ($submission->payload_json['pending_item']['response_document'] ?? null)) {
                    throw new DomainException('A resposta foi alterada após preparar o envio. Prepare outro dossiê.', 'pending_response_changed', 409);
                }
                $pending->update(['status' => 'respondida', 'response_submission_id' => $submission->id, 'resolved_at' => now()]);
                $process->pendencies()->where('external_pending_item_id', $pending->id)->update(['status' => 'resolvida', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution' => 'Resposta enviada: '.$data['external_receipt']]);
            }
            $targetType = $submission->kind === 'inspection' ? 'vistoria_solicitada' : 'enviado';
            $stillPending = $submission->externalProcess->pendingItems()->where('status', 'aberta')->exists();
            if (! $stillPending || $submission->kind !== 'correction') {
                $target = app(WorkflowDefinition::class)->transitions($process)->first(fn ($stage) => $stage->stage_type === $targetType);
                if (! $target) {
                    throw new DomainException('Configure a próxima etapa para registrar este envio.', 'submission_stage_invalid', 409);
                }
                app(ProcessWorkflow::class)->transition($process, $target->code, $actor, ['protocol_number' => $protocol, 'submission_id' => $submission->uuid]);
            }
            $this->event($process, $actor, 'IN', 'assisted_confirmation', true, ['protocol_number' => $protocol, 'external_receipt' => $data['external_receipt']], $submission);

            return $submission->refresh();
        });
    }

    public function fail(HomologationProcess $process, ExternalSubmission $submission, User $actor, string $reason): void
    {
        DB::transaction(function () use ($process, $submission, $actor, $reason) {
            HomologationProcess::query()->whereKey($process->id)->lockForUpdate()->firstOrFail();
            $submission = $process->submissions()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $submission->markFailed();
            $this->event($process, $actor, 'IN', 'assisted_failure', false, ['reason' => $reason], $submission);
        });
    }

    public function document(HomologationProcess $process, string $uuid, ?string $type = null): ProcessDocument
    {
        return ProcessDocument::query()->where('uuid', $uuid)->where('solar_project_id', $process->solar_project_id)
            ->where(fn ($q) => $q->whereNull('homologation_process_id')->orWhere('homologation_process_id', $process->id))
            ->when($type, fn ($q) => $q->whereIn('document_type', array_filter(array_column(DocumentTypes::options(), 'value'), fn ($key) => DocumentTypes::legacy($key) === $type)))->firstOrFail();
    }

    /** @param array<string, mixed> $payload */
    public function event(HomologationProcess $process, User $actor, string $direction, string $type, bool $success, array $payload, ?ExternalSubmission $submission = null): void
    {
        IntegrationEvent::create(['homologation_process_id' => $process->id, 'submission_id' => $submission?->id, 'direction' => $direction, 'event_type' => $type,
            'request_hash' => $submission?->request_hash, 'response_payload_json' => app(Redactor::class)->redact($payload), 'success' => $success, 'occurred_at' => now(), 'created_by' => $actor->id]);
    }
}
