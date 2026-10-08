<?php

namespace App\Domain\Homologations;

use App\Domain\Distributors\IntegrationService;
use App\Domain\Distributors\Models\ExternalPendingItem;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Enums\WorkflowStage;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\ProcessPendency;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Users\Enums\PermissionKey;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto de mudança de status. Cada transição valida a máquina de estados
 * e os pré-requisitos de negócio, e grava histórico imutável.
 */
final class ProcessWorkflow
{
    /**
     * Lista de bloqueios para avançar a "pronto para envio". Vazia = dossiê completo.
     *
     * @return list<string>
     */
    public function readinessIssues(HomologationProcess $process): array
    {
        return app(ValidationService::class)->issues($process);
    }

    /**
     * @param  array{reason?: string|null, protocol_number?: string|null}  $input
     */
    public function transition(HomologationProcess $process, ProcessStatus|string $target, User $actor, array $input = []): HomologationProcess
    {
        return DB::transaction(function () use ($process, $target, $actor, $input) {
            $locked = HomologationProcess::query()->whereKey($process->id)->lockForUpdate()->firstOrFail();

            return $this->transitionLocked($locked, $target, $actor, $input);
        });
    }

    /** @param array<string, mixed> $input */
    private function transitionLocked(HomologationProcess $process, ProcessStatus|string $target, User $actor, array $input): HomologationProcess
    {
        $stage = app(WorkflowDefinition::class)->stageFor($target instanceof ProcessStatus ? $target->value : $target);
        $previousStage = $process->currentStage ?? app(WorkflowDefinition::class)->stageFor($process->status->value);
        $target = ProcessStatus::from($stage->stage_type);
        $current = $process->status;
        $reason = isset($input['reason']) ? trim((string) $input['reason']) : null;

        if (! $previousStage->canTransitionTo($stage)) {
            throw new DomainException(
                "Transição não permitida: {$current->label()} → {$target->label()}.",
                'invalid_transition',
            );
        }

        if ($target === ProcessStatus::Cancelado && ! $actor->hasPermission(PermissionKey::ProcessCancel)) {
            throw new DomainException('Você não tem permissão para cancelar processos.', 'forbidden_transition', 403);
        }

        if (in_array($target, [ProcessStatus::Cancelado, ProcessStatus::Reprovado, ProcessStatus::PendenciaDistribuidora, ProcessStatus::Rascunho], true)
            && ($reason === null || $reason === '')) {
            throw new DomainException('Informe a justificativa desta transição.', 'reason_required');
        }

        if ($target === ProcessStatus::ProntoParaEnvio) {
            app(ValidationService::class)->ensureReady($process);
        }

        if ($target === ProcessStatus::Enviado) {
            $submission = $process->submissions()->where('uuid', $input['submission_id'] ?? '')->where('status', 'sent')->first();
            if (! $submission) {
                throw new DomainException('Prepare o envio e registre a confirmação com protocolo e comprovante.', 'submission_required');
            }
            $protocol = trim((string) ($input['protocol_number'] ?? $process->protocol_number ?? ''));
            if ($protocol === '') {
                throw new DomainException('Informe o número de protocolo da distribuidora para registrar o envio.', 'protocol_required');
            }
            $this->assertProtocolAvailable($process, $protocol);

            if ($current === ProcessStatus::PendenciaDistribuidora && $process->openPendencies()->exists()) {
                throw new DomainException('Resolva as pendências da distribuidora antes de reenviar.', 'pendencies_open');
            }
        }

        if ($target === ProcessStatus::VistoriaSolicitada && ! $process->submissions()->where('uuid', $input['submission_id'] ?? '')->where('kind', 'inspection')->where('status', 'sent')->exists()) {
            throw new DomainException('Registre o envio da solicitação de vistoria.', 'inspection_submission_required');
        }
        if ($target === ProcessStatus::Conectado) {
            $external = $process->externalProcess;
            if (! $external || ! $external->inspections()->with('report')->get()->contains(fn ($inspection) => $inspection->isConnectionEvidence())) {
                throw new DomainException('Registre uma vistoria aprovada, seu relatório revisado e a data de aprovação da conexão.', 'connection_evidence_required');
            }
            if ($process->openPendencies()->exists() || $external->pendingItems()->where('status', 'aberta')->exists()) {
                throw new DomainException('Resolva as pendências antes de encerrar a conexão.', 'pendencies_open');
            }
        }

        return DB::transaction(function () use ($process, $target, $actor, $reason, $input, $current, $stage): HomologationProcess {
            $process->status = $target;
            $process->stage = match ($target) {
                ProcessStatus::Enviado, ProcessStatus::EmAnalise => WorkflowStage::ExternalAnalysis,
                ProcessStatus::PendenciaDistribuidora => WorkflowStage::Correction,
                ProcessStatus::Aprovado => WorkflowStage::Execution,
                ProcessStatus::VistoriaSolicitada => WorkflowStage::Inspection,
                ProcessStatus::Conectado => WorkflowStage::Connection,
                default => WorkflowStage::Preparation,
            };
            $process->stage_changed_at = now();
            $process->status_changed_at = now();
            $process->current_stage_id = $stage->id;
            $process->completed_at = $target->isTerminal() ? now() : null;

            match ($target) {
                ProcessStatus::Enviado => $this->markSubmitted($process, (string) ($input['protocol_number'] ?? $process->protocol_number)),
                ProcessStatus::Aprovado => $process->approved_at = now(),
                ProcessStatus::Conectado => $process->connected_at = now(),
                default => null,
            };

            $process->save();
            $process->stageHistory()->whereNull('left_at')->update(['left_at' => now()]);
            $process->stageHistory()->create(['workflow_stage_id' => $stage->id, 'entered_at' => now(), 'changed_by' => $actor->id, 'notes' => $reason]);

            $process->history()->create([
                'from_status' => $current->value,
                'to_status' => $target->value,
                'user_id' => $actor->id,
                'reason' => $reason ?: null,
            ]);

            if ($target === ProcessStatus::PendenciaDistribuidora && empty($input['external_pending_item_id'])) {
                $external = app(IntegrationService::class)->external($process);
                $pending = ExternalPendingItem::create(['external_process_id' => $external->id, 'description' => $reason, 'status' => 'aberta']);
                ProcessPendency::create([
                    'homologation_process_id' => $process->id,
                    'external_pending_item_id' => $pending->id,
                    'origin' => 'distribuidora',
                    'title' => str($reason)->limit(180)->toString(),
                    'description' => $reason,
                    'status' => 'aberta',
                    'created_by' => $actor->id,
                ]);
            }

            return $process->refresh();
        });
    }

    public function assertProtocolAvailable(HomologationProcess $process, string $protocol): void
    {
        $taken = HomologationProcess::query()
            ->where('distributor_id', $process->distributor_id)
            ->where('protocol_number', $protocol)
            ->whereKeyNot($process->id)
            ->value('code');

        if ($taken) {
            throw new DomainException("O protocolo {$protocol} já está vinculado ao processo {$taken}.", 'protocol_duplicated', 409);
        }
    }

    private function markSubmitted(HomologationProcess $process, string $protocol): void
    {
        $process->protocol_number = trim($protocol);
        $process->submitted_at ??= now();
    }

    public function resolvePendency(ProcessPendency $pendency, User $user, string $resolution): ProcessPendency
    {
        if ($pendency->status === 'resolvida') {
            throw new DomainException('Esta pendência já foi resolvida.');
        }

        $pendency->forceFill([
            'status' => 'resolvida', 'resolution' => $resolution,
            'resolved_by' => $user->id, 'resolved_at' => now(),
        ])->save();

        app(TimelineRecorder::class)->record($pendency->process, 'PENDENCY_RESOLVED', "Pendência resolvida: {$pendency->title}", $resolution, null, $user->id);

        return $pendency;
    }
}
