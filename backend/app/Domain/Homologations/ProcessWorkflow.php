<?php

namespace App\Domain\Homologations;

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
use App\Domain\Projects\ProjectVersionFreezer;
use App\Domain\Rules\Enums\RequirementPhase;
use App\Domain\Rules\RequirementEngine;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto de mudança de etapa/status. Cada ação valida a etapa atual, os
 * requisitos configurados da fase e grava timeline. O frontend não decide nada.
 */
final class ProcessWorkflow
{
    public function __construct(
        private readonly RequirementEngine $requirements,
        private readonly ProjectEvaluator $evaluator,
        private readonly ProjectVersionFreezer $freezer,
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

        $inspection = $stage === WorkflowStage::Execution
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
            'request_inspection' => $gate($stage === WorkflowStage::Execution, 'Vistoria só pode ser solicitada na etapa de Execução.', $inspection),
            'record_inspection' => $gate($stage === WorkflowStage::Inspection && $openInspection !== null, 'Não há vistoria em aberto.'),
            'record_connection_event' => $gate($stage === WorkflowStage::Connection, 'Eventos de conexão só após vistoria aprovada.'),
            'complete' => $gate($stage === WorkflowStage::Connection, 'Conclusão só na etapa de Conexão.', $completion),
            'cancel' => $gate(true, ''),
        ];
    }

    public function submit(HomologationProcess $process, User $user, string $protocolNumber): HomologationProcess
    {
        return DB::transaction(function () use ($process, $user, $protocolNumber) {
            $process = $this->lock($process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::Preparation, WorkflowStage::Correction], 'enviar à distribuidora');

            $project = $this->evaluator->evaluate($process->project);
            if ($project->classification === null) {
                throw new DomainException('O projeto não possui classificação válida pelas regras vigentes.', 'classification_missing');
            }

            $checklist = $this->requirements->evaluateAndRecord($project, RequirementPhase::Submission, $process);
            if ($checklist['blocking'] !== []) {
                throw new DomainException('Requisitos de envio pendentes: '.implode('; ', $checklist['blocking']), 'requirements_pending');
            }

            $isResubmission = $process->stage === WorkflowStage::Correction;
            $process->protocol_number = trim($protocolNumber);
            $version = $this->freezer->freeze($project, $process, $isResubmission ? 'RESUBMISSION' : 'SUBMISSION', $checklist, $user->id);

            $process->project_version_id = $version->id;
            $process->submitted_at ??= now();
            $this->moveTo($process, WorkflowStage::ExternalAnalysis);
            $process->save();

            $this->deadlines->open($process, DeadlineType::AccessOpinion);
            $this->timeline->record($process, $isResubmission ? 'RESUBMITTED' : 'SUBMITTED',
                $isResubmission ? 'Reenviado à distribuidora' : 'Enviado à distribuidora',
                "Protocolo {$process->protocol_number} · versão {$version->version} do projeto congelada.",
                ['project_version' => $version->version, 'sha256' => $version->snapshot_sha256], $user->id);

            return $process;
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

            foreach ($items as $title) {
                $process->pendencies()->create([
                    'origin' => 'distribuidora', 'title' => $title, 'description' => $notes,
                    'status' => 'aberta', 'created_by' => $user->id,
                ]);
            }

            $this->deadlines->close($process, DeadlineType::AccessOpinion);
            $this->moveTo($process, WorkflowStage::Correction);
            $process->save();

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
            $process->approved_at = now();
            $this->deadlines->close($process, DeadlineType::AccessOpinion);
            $this->moveTo($process, WorkflowStage::Execution);
            $process->save();

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
            $this->assertStage($process, [WorkflowStage::Execution], 'solicitar vistoria');

            $checklist = $this->requirements->evaluateAndRecord($process->project, RequirementPhase::InspectionRequest, $process);
            if ($checklist['blocking'] !== []) {
                throw new DomainException('Vistoria indisponível: '.implode('; ', $checklist['blocking']), 'inspection_not_eligible');
            }

            $inspection = $process->inspections()->create([
                'sequence' => ((int) $process->inspections()->max('sequence')) + 1,
                'requested_at' => now(),
                'scheduled_for' => $scheduledFor,
                'requested_by' => $user->id,
            ]);
            $inspection->forceFill(['status' => $scheduledFor ? InspectionStatus::Scheduled : InspectionStatus::Requested])->save();

            $this->moveTo($process, WorkflowStage::Inspection);
            $process->save();

            $this->deadlines->open($process, DeadlineType::Inspection);
            $label = $inspection->sequence > 1 ? "Revistoria #{$inspection->sequence} solicitada" : 'Vistoria solicitada';
            $this->timeline->record($process, 'INSPECTION_REQUESTED', $label, null, ['sequence' => $inspection->sequence], $user->id);

            return $inspection;
        });
    }

    public function scheduleInspection(Inspection $inspection, User $user, string $date): Inspection
    {
        return DB::transaction(function () use ($inspection, $user, $date) {
            $process = $this->lock($inspection->process);
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
    public function recordInspectionResult(Inspection $inspection, User $user, bool $approved, ?string $notes): Inspection
    {
        return DB::transaction(function () use ($inspection, $user, $approved, $notes) {
            $process = $this->lock($inspection->process);
            $this->assertActive($process);
            $this->assertStage($process, [WorkflowStage::Inspection], 'registrar resultado de vistoria');

            if (! $inspection->status->isOpen()) {
                throw new DomainException('Esta vistoria já possui resultado registrado e não pode ser alterada.', 'inspection_closed');
            }

            if (! $approved && blank($notes)) {
                throw new DomainException('Descreva o motivo da reprovação.');
            }

            $pendency = null;
            if (! $approved) {
                $pendency = $process->pendencies()->create([
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
            $this->moveTo($process, $approved ? WorkflowStage::Connection : WorkflowStage::Execution);
            $process->save();

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

            $process->status = ProcessStatus::Completed;
            $process->completed_at = now();
            $process->save();

            $this->timeline->record($process, 'COMPLETED', 'Homologação concluída', null, null, $user->id);

            return $process;
        });
    }

    public function cancel(HomologationProcess $process, User $user, string $reason): HomologationProcess
    {
        return DB::transaction(function () use ($process, $user, $reason) {
            $process = $this->lock($process);
            $this->assertActive($process);

            $process->status = ProcessStatus::Cancelled;
            $process->cancelled_at = now();
            $process->save();

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
}
