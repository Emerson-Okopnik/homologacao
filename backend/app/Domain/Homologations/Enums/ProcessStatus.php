<?php

namespace App\Domain\Homologations\Enums;

/**
 * Máquina de estados do processo de homologação (acesso à rede de micro/minigeração).
 */
enum ProcessStatus: string
{
    case Rascunho = 'rascunho';
    case EmPreparacao = 'em_preparacao';
    case ProntoParaEnvio = 'pronto_para_envio';
    case Enviado = 'enviado';
    case EmAnalise = 'em_analise';
    case PendenciaDistribuidora = 'pendencia_distribuidora';
    case Aprovado = 'aprovado';
    case VistoriaSolicitada = 'vistoria_solicitada';
    case Conectado = 'conectado';
    case Reprovado = 'reprovado';
    case Cancelado = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::EmPreparacao => 'Em preparação',
            self::ProntoParaEnvio => 'Pronto para envio',
            self::Enviado => 'Enviado',
            self::EmAnalise => 'Em análise',
            self::PendenciaDistribuidora => 'Pendência da distribuidora',
            self::Aprovado => 'Parecer aprovado',
            self::VistoriaSolicitada => 'Vistoria solicitada',
            self::Conectado => 'Conectado',
            self::Reprovado => 'Reprovado',
            self::Cancelado => 'Cancelado',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Rascunho => [self::EmPreparacao, self::Cancelado],
            self::EmPreparacao => [self::ProntoParaEnvio, self::Rascunho, self::Cancelado],
            self::ProntoParaEnvio => [self::Enviado, self::EmPreparacao, self::Cancelado],
            self::Enviado => [self::EmAnalise, self::PendenciaDistribuidora, self::Cancelado],
            self::EmAnalise => [self::Aprovado, self::PendenciaDistribuidora, self::Reprovado, self::Cancelado],
            self::PendenciaDistribuidora => [self::Enviado, self::Cancelado],
            self::Aprovado => [self::VistoriaSolicitada, self::Cancelado],
            self::VistoriaSolicitada => [self::Conectado, self::PendenciaDistribuidora, self::Cancelado],
            self::Reprovado => [self::EmPreparacao, self::Cancelado],
            self::Conectado, self::Cancelado => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Status em que o dossiê é editável (upload de documentos, troca de RT/equipamentos).
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Rascunho, self::EmPreparacao, self::PendenciaDistribuidora, self::Reprovado], true);
    }

    /** Fase de entrada da situação. O cancelamento pode ocorrer em qualquer fase. */
    public function workflowStage(): ?WorkflowStage
    {
        return match ($this) {
            self::Rascunho, self::EmPreparacao, self::ProntoParaEnvio, self::Reprovado => WorkflowStage::Preparation,
            self::Enviado, self::EmAnalise => WorkflowStage::ExternalAnalysis,
            self::PendenciaDistribuidora => WorkflowStage::Correction,
            self::Aprovado => WorkflowStage::Execution,
            self::VistoriaSolicitada => WorkflowStage::Inspection,
            self::Conectado => WorkflowStage::Connection,
            self::Cancelado => null,
        };
    }

    /**
     * Ordem das colunas do Kanban.
     *
     * @return list<self>
     */
    public static function board(): array
    {
        return [
            self::Rascunho, self::EmPreparacao, self::ProntoParaEnvio, self::Enviado, self::EmAnalise,
            self::PendenciaDistribuidora, self::Aprovado, self::VistoriaSolicitada, self::Conectado,
        ];
    }
}
