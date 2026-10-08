<?php

namespace App\Domain\Homologations\Enums;

enum InspectionStatus: string
{
    case Requested = 'REQUESTED';
    case Scheduled = 'SCHEDULED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitada',
            self::Scheduled => 'Agendada',
            self::Approved => 'Aprovada',
            self::Rejected => 'Reprovada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Scheduled], true);
    }
}
