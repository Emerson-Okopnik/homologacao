<?php

namespace App\Domain\Homologations;

use App\Domain\Documents\DocumentRequirements;
use App\Domain\Homologations\Enums\ProcessStatus;
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
        $process->loadMissing(['project.technicalResponsible', 'project.equipment', 'currentDocuments', 'openPendencies']);
        $project = $process->project;
        $issues = [];

        $rt = $project->technicalResponsible;
        if (! $rt) {
            $issues[] = 'Defina o responsável técnico do projeto.';
        } elseif (! $rt->active || $rt->registration_status !== 'regular') {
            $issues[] = "O responsável técnico {$rt->name} não está ativo/regular.";
        }

        $types = $project->equipment->pluck('type');
        if (! $types->contains('module')) {
            $issues[] = 'Informe os módulos fotovoltaicos do projeto.';
        }
        if (! $types->contains('inverter')) {
            $issues[] = 'Informe os inversores do projeto.';
        }
        if ($project->has_battery && ! $types->contains('battery')) {
            $issues[] = 'O projeto indica armazenamento, mas nenhuma bateria foi informada.';
        }

        $documents = $process->currentDocuments->keyBy('document_type');
        foreach (DocumentRequirements::requiredFor($project) as $type) {
            $doc = $documents->get($type);
            $label = DocumentRequirements::label($type);
            if (! $doc) {
                $issues[] = "Documento obrigatório ausente: {$label}.";
            } elseif ($doc->review_status !== 'aprovado') {
                $issues[] = "Documento não aprovado na revisão interna: {$label}.";
            }
        }

        $open = $process->openPendencies->count();
        if ($open > 0) {
            $issues[] = "Existem {$open} pendência(s) em aberto.";
        }

        return $issues;
    }

    /**
     * @param  array{reason?: string|null, protocol_number?: string|null}  $input
     */
    public function transition(HomologationProcess $process, ProcessStatus $target, User $actor, array $input = []): HomologationProcess
    {
        $current = $process->status;
        $reason = isset($input['reason']) ? trim((string) $input['reason']) : null;

        if (! $current->canTransitionTo($target)) {
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
            $issues = $this->readinessIssues($process);
            if ($issues !== []) {
                throw new DomainException('O dossiê ainda não está completo: '.implode(' ', $issues), 'process_not_ready');
            }
        }

        if ($target === ProcessStatus::Enviado) {
            $protocol = trim((string) ($input['protocol_number'] ?? $process->protocol_number ?? ''));
            if ($protocol === '') {
                throw new DomainException('Informe o número de protocolo da distribuidora para registrar o envio.', 'protocol_required');
            }
            $this->assertProtocolAvailable($process, $protocol);

            if ($current === ProcessStatus::PendenciaDistribuidora && $process->openPendencies()->exists()) {
                throw new DomainException('Resolva as pendências da distribuidora antes de reenviar.', 'pendencies_open');
            }
        }

        return DB::transaction(function () use ($process, $target, $actor, $reason, $input, $current): HomologationProcess {
            $process->status = $target;
            $process->status_changed_at = now();

            match ($target) {
                ProcessStatus::Enviado => $this->markSubmitted($process, (string) ($input['protocol_number'] ?? $process->protocol_number)),
                ProcessStatus::Aprovado => $process->approved_at = now(),
                ProcessStatus::Conectado => $process->connected_at = now(),
                default => null,
            };

            $process->save();

            $process->history()->create([
                'tenant_id' => $process->tenant_id,
                'from_status' => $current->value,
                'to_status' => $target->value,
                'user_id' => $actor->id,
                'reason' => $reason ?: null,
            ]);

            if ($target === ProcessStatus::PendenciaDistribuidora) {
                ProcessPendency::create([
                    'homologation_process_id' => $process->id,
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
}
