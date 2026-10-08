<?php

namespace App\Domain\Homologations\Enums;

enum NetworkWorkStatus: string
{
    case NotRequired = 'NOT_REQUIRED';
    case UnderAnalysis = 'UNDER_ANALYSIS';
    case Required = 'REQUIRED';
    case WaitingExecution = 'WAITING_EXECUTION';
    case Completed = 'COMPLETED';
    case Released = 'RELEASED';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Sem obra de rede',
            self::UnderAnalysis => 'Em análise',
            self::Required => 'Obra necessária',
            self::WaitingExecution => 'Aguardando execução da obra',
            self::Completed => 'Obra concluída',
            self::Released => 'Obra liberada',
        };
    }

    /** Há (ou houve) obra de rede no atendimento. */
    public function isRequired(): bool
    {
        return in_array($this, [self::Required, self::WaitingExecution, self::Completed, self::Released], true);
    }

    /** A situação da rede não impede a vistoria. */
    public function isCleared(): bool
    {
        return in_array($this, [self::NotRequired, self::Completed, self::Released], true);
    }
}
