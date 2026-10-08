<?php

namespace App\Domain\Projects\Enums;

enum FastTrackParty: string
{
    case Requester = 'REQUESTER';
    case TechnicalResponsible = 'TECHNICAL_RESPONSIBLE';

    public function label(): string
    {
        return match ($this) {
            self::Requester => 'Solicitante (titular da UC)',
            self::TechnicalResponsible => 'Responsável técnico',
        };
    }
}
