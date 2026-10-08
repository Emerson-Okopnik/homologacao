<?php

namespace App\Domain\Homologations\Enums;

enum WorkflowStage: string
{
    case Preparation = 'PREPARATION';
    case ExternalAnalysis = 'EXTERNAL_ANALYSIS';
    case Correction = 'CORRECTION';
    case Execution = 'EXECUTION';
    case Inspection = 'INSPECTION';
    case Connection = 'CONNECTION';

    public function label(): string
    {
        return match ($this) {
            self::Preparation => 'Preparação',
            self::ExternalAnalysis => 'Análise da distribuidora',
            self::Correction => 'Correção',
            self::Execution => 'Execução',
            self::Inspection => 'Vistoria',
            self::Connection => 'Conexão',
        };
    }

    /** Etapas em que os dados técnicos do projeto podem ser alterados. */
    public function allowsProjectEdit(): bool
    {
        return in_array($this, [self::Preparation, self::Correction], true);
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Preparation, self::ExternalAnalysis, self::Correction, self::Execution, self::Inspection, self::Connection];
    }
}
