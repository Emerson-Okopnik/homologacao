<?php

namespace App\Domain\Homologations\Enums;

/**
 * Status macro do processo. As fases do trabalho ficam em WorkflowStage.
 */
enum ProcessStatus: string
{
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Em andamento',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }
}
