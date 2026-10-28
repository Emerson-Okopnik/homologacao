<?php

namespace App\Domain\Homologations;

use App\Domain\Distributors\IntegrationService;
use App\Domain\Distributors\Models\ExternalPendingItem;
use App\Domain\Distributors\Models\Inspection as ExternalInspection;
use App\Domain\Homologations\Enums\ConnectionEventType;
use App\Domain\Homologations\Enums\DeadlineType;
use App\Domain\Homologations\Enums\InspectionStatus;
use App\Domain\Homologations\Enums\NetworkWorkStatus;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Enums\WorkflowStage;
use App\Domain\Homologations\Models\ConnectionEvent;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Inspection;
use App\Domain\Homologations\Models\ProcessPendency;
use App\Domain\Homologations\Models\ProjectExecution;
use App\Domain\Projects\ProjectEvaluator;
use App\Domain\Rules\Enums\RequirementPhase;
use App\Domain\Rules\RequirementEngine;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Único ponto de mudança de etapa/status. Cada ação valida a etapa atual, os
 * requisitos configurados da fase e grava timeline. O frontend não decide nada.
 */
final class ProcessActionWorkflow
{
    public function __construct(
        private readonly RequirementEngine $requirements,
        private readonly ProjectEvaluator $evaluator,
        private readonly DeadlineCalculator $deadlines,
        private readonly TimelineRecorder $timeline,
    ) {}

    /**
     * Ações disponíveis agora e, para as indisponíveis, o motivo.
     *
     * @return array<string, array{available: bool, reasons: list<string>}>
     */
    public function availableActions(HomologationProcess $process): array
    {
        $stage = $process->stage;
        $active = $process->isActive();
        $project = $process->project;

        $gate = function (bool $stageOk, string $stageMsg, array $extra = []) use ($active): array {
            $reasons = [];
            if (! $active) {
                $reasons[] = 'O processo não está em andamento.';
            } elseif (! $stageOk) {
                $reasons[] = $stageMsg;
            } else {
                $reasons = $extra;
            }

            return ['available' => $reasons === [], 'reasons' => array_values($reasons)];
        };

        $inEditable = in_array($stage, [WorkflowStage::Preparation, WorkflowStage::Correction], true);
        $submission = $inEditable ? $this->requirements->evaluate($project, RequirementPhase::Submission, $process)['blocking'] : [];
        if ($inEditable && $project->classification === null) {
            array_unshift($submission, 'O projeto ainda não possui classificação válida.');
        }

        $inspection = $stage === WorkflowStage::Inspection
            ? $this->requirements->evaluate($project, RequirementPhase::InspectionRequest, $process)['blocking'] : [];
        $completion = $stage === WorkflowStage::Connection
            ? $this->requirements->evaluate($project, RequirementPhase::Completion, $process)['blocking'] : [];

        $openInspection = $process->inspections->first(fn (Inspection $i) => $i->status->isOpen());

        return [
            'submit' => $gate($inEditable, 'Envio só é possível em Preparação ou Correção.', $submission),
            'register_correction' => $gate($stage === WorkflowStage::ExternalAnalysis, 'Só durante a análise da distribuidora.'),
            'approve_access' => $gate($stage === WorkflowStage::ExternalAnalysis, 'Só durante a análise da distribuidora.'),
            'update_network_work' => $gate(in_array($stage, [WorkflowStage::ExternalAnalysis, WorkflowStage::Execution, WorkflowStage::Inspection], true), 'Situação da obra de rede só muda entre a análise e a vistoria.'),
            'report_execution' => $gate($stage === WorkflowStage::Execution, 'Execução só pode ser informada após a aprovação do acesso.'),
            'request_inspection' => $gate($stage === WorkflowStage::Inspection && $openInspection === null, 'Confirme primeiro o envio da solicitação de vistoria no acompanhamento.', $inspection),
            'record_inspection' => $gate($stage === WorkflowStage::Inspection && $openInspection !== null, 'Não há vistoria em aberto.'),
            'record_connection_event' => $gate($stage === WorkflowStage::Connection, 'Eventos de conexão só após vistoria aprovada.'),
            'complete' => $gate($stage === WorkflowStage::Connection, 'Conclusão só na etapa de Conexão.', $completion),
            'cancel' => $gate(true, ''),
        ];
    }

    public function submit(HomologationProcess $process, User $user, string $protocolNumber, string $receiptId, string $externalReceipt): HomologationProcess
    {
        return DB::transaction(function () use ($process, $user, $protocolNumber, $receiptId, $externalReceipt) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $project = $this->evaluator->evaluate($process->project);
            if ($project->classification === null) {
                throw new DomainException('O projeto não possui classificação válida pelas regras vigentes.', 'classification_missing');
            }
            $checklist = $this->requirements->evaluateAndRecord($project, RequirementPhase::Submission, $process);
            if ($checklist['blocking']) {
                throw new DomainException('Requisitos de envio pendentes: '.implode('; ', $checklist['blocking']), 'requirements_pending');
            }
            $workflow = app(ProcessWorkflow::class);
            if ($process->status === ProcessStatus::Rascunho) {
                $process = $workflow->transition($process, ProcessStatus::EmPreparacao, $user);
            }
            if ($process->status === ProcessStatus::EmPreparacao) {
                $process = $workflow->transition($process, ProcessStatus::ProntoParaEnvio, $user);
            }
            $kind = $process->status === ProcessStatus::PendenciaDistribuidora ? 'correction' : 'initial';
            $pending = $kind === 'correction' ? $process->externalProcess?->pendingItems()->where('status', 'aberta')->first() : null;
            $integration = app(IntegrationService::class);
            $submission = $integration->prepare($process, $user, ['kind' => $kind, 'idempotency_key' => (string) Str::uuid(), 'change_reason' => 'Envio confirmado pelo operador', 'pending_item_id' => $pending?->uuid, 'rule_checklist' => $checklist]);
            $integration->confirm($process, $submission, $user, ['protocol_number' => $protocolNumber, 'receipt_document_id' => $receiptId, 'external_receipt' => $externalReceipt]);
            $process->refresh()->update(['project_version_id' => $submission->project_version_id]);
            $this->deadlines->open($process, DeadlineType::AccessOpinion);
            $this->timeline->record($process, 'SUBMITTED', 'Envio confirmado à distribuidora', 'Versão '.$submission->version->version, null, $user->id);

            return $process->refresh();
        });
    }

    /**
     * @param  list<string>  $items
     */
    public function registerCorrection(HomologationProcess $process, User $user, array $items, ?string $notes): HomologationProcess
    {
        return DB::transaction(function () use ($process, $user, $items, $notes) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::ExternalAnalysis], 'registrar exigências');

            if ($items === []) {
                throw new DomainException('Informe ao menos uma exigência da distribuidora.');
            }

            $external = app(IntegrationService::class)->external($process);
            foreach ($items as $title) {
                $pending = ExternalPendingItem::create(['external_process_id' => $external->id, 'description' => $title, 'status' => 'aberta']);
                $process->pendencies()->create([
                    'external_pending_item_id' => $pending->id,
                    'origin' => 'distribuidora', 'title' => $title, 'description' => $notes,
                    'status' => 'aberta', 'created_by' => $user->id,
                ]);
            }

            $this->deadlines->close($process, DeadlineType::AccessOpinion);
            $process = $this->transition($process, ProcessStatus::PendenciaDistribuidora, $user, ['reason' => implode('; ', $items), 'external_pending_item_id' => $pending->id]);

            $this->timeline->record($process, 'CORRECTION_REQUESTED', 'Distribuidora solicitou correções',
                implode("\n", $items), null, $user->id);

            return $process;
        });
    }

    public function approveAccess(HomologationProcess $process, User $user, NetworkWorkStatus $network, ?string $notes): HomologationProcess
    {
        return DB::transaction(function () use ($process, $user, $network, $notes) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::ExternalAnalysis], 'aprovar o acesso');

            if ($network === NetworkWorkStatus::UnderAnalysis) {
                throw new DomainException('Ao aprovar o acesso, informe se há obra de rede (não pode ficar "em análise").');
            }

            $process->network_work_status = $network;
            $process->save();
            $this->deadlines->close($process, DeadlineType::AccessOpinion);
            if ($process->status === ProcessStatus::Enviado) {
                $process = $this->transition($process, ProcessStatus::EmAnalise, $user);
            }
            $process = $this->transition($process, ProcessStatus::Aprovado, $user);

            $this->timeline->record($process, 'ACCESS_APPROVED', 'Parecer de acesso aprovado',
                trim("Obra de rede: {$network->label()}. ".($notes ?? '')), ['network_work_status' => $network->value], $user->id);

            return $process;
        });
    }

    public function updateNetworkWork(HomologationProcess $process, User $user, NetworkWorkStatus $status, ?string $notes): HomologationProcess
    {
        return DB::transaction(function () use ($process, $user, $status, $notes) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::ExternalAnalysis, WorkflowStage::Execution, WorkflowStage::Inspection], 'alterar a obra de rede');

            $previous = $process->network_work_status;
            $process->network_work_status = $status;
            $process->save();

            $this->timeline->record($process, 'NETWORK_WORK_UPDATED', 'Situação da obra de rede atualizada',
                trim("{$previous->label()} → {$status->label()}. ".($notes ?? '')),
                ['from' => $previous->value, 'to' => $status->value], $user->id);

            return $process;
        });
    }

    /**
     * @param  array{started_at?: string|null, completed_at: string, notes?: string|null}  $data
     */
    public function reportExecution(HomologationProcess $process, User $user, array $data): ProjectExecution
    {
        return DB::transaction(function () use ($process, $user, $data) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::Execution], 'informar a execução');

            $execution = $process->execution()->updateOrCreate([], [
                'started_at' => $data['started_at'] ?? null,
                'completed_at' => $data['completed_at'],
                'notes' => $data['notes'] ?? null,
                'reported_by' => $user->id,
            ]);

            $this->timeline->record($process, 'EXECUTION_REPORTED', 'Execução da obra informada',
                'Concluída em '.$execution->completed_at->format('d/m/Y').'.', null, $user->id);

            return $execution;
        });
    }

    public function requestInspection(HomologationProcess $process, User $user, ?string $scheduledFor): Inspection
    {
        return DB::transaction(function () use ($process, $user, $scheduledFor) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $submission = $process->submissions()->where('kind', 'inspection')->where('status', 'sent')->latest('id')->first();
            if (! $submission || $process->status !== ProcessStatus::VistoriaSolicitada) {
                throw new DomainException('Prepare e confirme o envio da solicitação de vistoria na seção de acompanhamento.', 'inspection_submission_required');
            }
            if ($process->inspections()->get()->contains(fn ($i) => $i->status->isOpen())) {
                throw new DomainException('Já existe vistoria em aberto.', 'inspection_open');
            }
            $checklist = $this->requirements->evaluateAndRecord($process->project, RequirementPhase::InspectionRequest, $process);
            if ($checklist['blocking']) {
                throw new DomainException('Vistoria indisponível: '.implode('; ', $checklist['blocking']), 'inspection_not_eligible');
            }
            $inspection = $process->inspections()->create(['sequence' => ((int) $process->inspections()->max('sequence')) + 1, 'requested_at' => now(), 'scheduled_for' => $scheduledFor, 'requested_by' => $user->id]);
            $inspection->forceFill(['submission_id' => $submission->id, 'status' => $scheduledFor ? InspectionStatus::Scheduled : InspectionStatus::Requested])->save();
            $this->deadlines->open($process, DeadlineType::Inspection);
            $this->timeline->record($process, 'INSPECTION_REQUESTED', 'Vistoria registrada após confirmação do envio', null, null, $user->id);

            return $inspection;
        });
    }

    public function scheduleInspection(Inspection $inspection, User $user, string $date): Inspection
    {
        return DB::transaction(function () use ($inspection, $user, $date) {
            $process = $this->lock($inspection->process);
            $this->assertActive($process);
            if (! $inspection->status->isOpen()) {
                throw new DomainException('Só é possível agendar uma vistoria em aberto.');
            }

            $inspection->forceFill(['scheduled_for' => $date, 'status' => InspectionStatus::Scheduled])->save();
            $this->timeline->record($process, 'INSPECTION_SCHEDULED', "Vistoria #{$inspection->sequence} agendada",
                'Data: '.$inspection->scheduled_for->format('d/m/Y').'.', null, $user->id);

            return $inspection;
        });
    }

    /**
     * Reprovação: abre pendência e volta para Execução. O registro da vistoria nunca é reescrito.
     */
    public function recordInspectionResult(Inspection $inspection, User $user, bool $approved, ?string $notes, string $reportId, string $performedAt): Inspection
    {
        return DB::transaction(function () use ($inspection, $user, $approved, $notes, $reportId, $performedAt) {
            $process = $this->lock($inspection->process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::Inspection], 'registrar resultado de vistoria');

            if (! $inspection->status->isOpen()) {
                throw new DomainException('Esta vistoria já possui resultado registrado e não pode ser alterada.', 'inspection_closed');
            }

            if (! $approved && blank($notes)) {
                throw new DomainException('Descreva o motivo da reprovação.');
            }
            $integration = app(IntegrationService::class);
            $report = $integration->document($process, $reportId, 'relatorio_vistoria');
            if (! $report->isValid() || ! $report->verifyHash()) {
                throw new DomainException('Revise e aprove o relatório da vistoria antes de registrar o resultado.', 'inspection_report_required');
            }
            if (CarbonImmutable::parse($performedAt)->lt($inspection->requested_at)) {
                throw new DomainException('A vistoria não pode ser realizada antes da solicitação.', 'inspection_date_invalid');
            }
            $external = $integration->external($process);
            ExternalInspection::create(['external_process_id' => $external->id, 'submission_id' => $inspection->submission_id,
                'requested_at' => $inspection->requested_at, 'scheduled_at' => $inspection->scheduled_for, 'performed_at' => $performedAt,
                'status' => $approved ? 'aprovada' : 'reprovada', 'report_document_id' => $report->id]);

            $pendency = null;
            if (! $approved) {
                $pending = ExternalPendingItem::create(['external_process_id' => $external->id, 'description' => $notes, 'status' => 'aberta']);
                $pendency = $process->pendencies()->create([
                    'external_pending_item_id' => $pending->id,
                    'origin' => 'vistoria',
                    'title' => "Reprovação na vistoria #{$inspection->sequence}",
                    'description' => $notes,
                    'status' => 'aberta',
                    'created_by' => $user->id,
                ]);
            }

            $inspection->forceFill([
                'status' => $approved ? InspectionStatus::Approved : InspectionStatus::Rejected,
                'result_at' => now(),
                'result_notes' => $notes,
                'pendency_id' => $pendency?->id,
                'recorded_by' => $user->id,
            ])->save();

            $this->deadlines->close($process, DeadlineType::Inspection);
            if ($approved) {
                $this->moveTo($process, WorkflowStage::Connection);
                $process->save();
            } else {
                $this->transition($process, ProcessStatus::PendenciaDistribuidora, $user, ['reason' => $notes, 'external_pending_item_id' => $pending->id]);
            }

            $this->timeline->record($process, $approved ? 'INSPECTION_APPROVED' : 'INSPECTION_REJECTED',
                $approved ? "Vistoria #{$inspection->sequence} aprovada" : "Vistoria #{$inspection->sequence} reprovada",
                $notes, ['sequence' => $inspection->sequence], $user->id);

            return $inspection;
        });
    }

    /**
     * @param  array{type: string, occurred_at: string, meter_number?: string|null, notes?: string|null}  $data
     */
    public function recordConnectionEvent(HomologationProcess $process, User $user, array $data): ConnectionEvent
    {
        return DB::transaction(function () use ($process, $user, $data) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::Connection], 'registrar evento de conexão');

            $type = ConnectionEventType::from($data['type']);
            if ($type === ConnectionEventType::MeterInstalled && blank($data['meter_number'] ?? null)) {
                throw new DomainException('Informe o número do medidor instalado.');
            }
            if ($type === ConnectionEventType::SystemConnected) {
                $inspection = $process->externalProcess?->inspections()->where('status', 'aprovada')->latest('id')->first();
                if (! $inspection || ! $inspection->report?->isValid() || ! $inspection->report->verifyHash()) {
                    throw new DomainException('Registre a vistoria aprovada com relatório válido antes da conexão.', 'connection_evidence_required');
                }
                if (CarbonImmutable::parse($data['occurred_at'])->lt($inspection->performed_at)) {
                    throw new DomainException('A conexão não pode ocorrer antes da vistoria.', 'connection_date_invalid');
                }
                $inspection->update(['connection_approved_at' => $data['occurred_at']]);
            }

            $event = $process->connectionEvents()->create([
                'type' => $type,
                'occurred_at' => $data['occurred_at'],
                'meter_number' => $data['meter_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $user->id,
            ]);

            $this->timeline->record($process, 'CONNECTION_EVENT', $type->label(),
                $event->meter_number ? "Medidor {$event->meter_number}." : $event->notes, ['type' => $type->value], $user->id);

            return $event;
        });
    }

    public function complete(HomologationProcess $process, User $user): HomologationProcess
    {
        return DB::transaction(function () use ($process, $user) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::Connection], 'concluir');

            $checklist = $this->requirements->evaluateAndRecord($process->project, RequirementPhase::Completion, $process);
            if ($checklist['blocking'] !== []) {
                throw new DomainException('Conclusão bloqueada: '.implode('; ', $checklist['blocking']), 'completion_blocked');
            }

            $process = app(ProcessWorkflow::class)->transition($process, ProcessStatus::Conectado, $user);

            $this->timeline->record($process, 'COMPLETED', 'Homologação concluída', null, null, $user->id);

            return $process;
        });
    }

    public function cancel(HomologationProcess $process, User $user, string $reason): HomologationProcess
    {
        return DB::transaction(function () use ($process, $user, $reason) {
            $process = $this->lock($process);
            $this->assertActive($process);

            $process = app(ProcessWorkflow::class)->transition($process, ProcessStatus::Cancelado, $user, ['reason' => $reason]);
            $process->forceFill(['cancelled_at' => now()])->save();

            foreach (DeadlineType::cases() as $type) {
                $this->deadlines->close($process, $type, 'SUPERSEDED');
            }

            $this->timeline->record($process, 'CANCELLED', 'Processo cancelado', $reason, null, $user->id);

            return $process;
        });
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

        $this->timeline->record($pendency->process, 'PENDENCY_RESOLVED', "Pendência resolvida: {$pendency->title}", $resolution, null, $user->id);

        return $pendency;
    }

    private function lock(HomologationProcess $process): HomologationProcess
    {
        return HomologationProcess::query()->whereKey($process->id)->lockForUpdate()->firstOrFail();
    }

    private function assertActive(HomologationProcess $process): void
    {
        if (! $process->isActive()) {
            throw new DomainException('O processo não está em andamento.', 'process_not_active');
        }
    }

    /**
     * @param  list<WorkflowStage>  $allowed
     */
    private function assertStage(HomologationProcess $process, array $allowed, string $action): void
    {
        if (! in_array($process->stage, $allowed, true)) {
            throw new DomainException("Não é possível {$action} na etapa \"{$process->stage->label()}\".", 'invalid_stage', 409);
        }
    }

    private function moveTo(HomologationProcess $process, WorkflowStage $stage): void
    {
        $process->stage = $stage;
        $process->stage_changed_at = now();
    }

    /** @param array<string, mixed> $data */
    private function transition(HomologationProcess $process, ProcessStatus $target, User $user, array $data = []): HomologationProcess
    {
        $stage = app(WorkflowDefinition::class)->transitions($process)->first(fn ($s) => $s->stage_type === $target->value);
        if (! $stage) {
            throw new DomainException('A configuração de etapas não permite esta ação.', 'invalid_transition', 409);
        }

        return app(ProcessWorkflow::class)->transition($process, $stage->code, $user, $data);
    }
}
