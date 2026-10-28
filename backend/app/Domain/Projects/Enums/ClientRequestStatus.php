<?php

namespace App\Domain\Projects\Enums;

enum ClientRequestStatus: string
{
    case Submitted = 'SUBMITTED';
    case InReview = 'IN_REVIEW';
    case NeedsInfo = 'NEEDS_INFO';
    case Converted = 'CONVERTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Recebida',
            self::InReview => 'Em análise pelo RT',
            self::NeedsInfo => 'Aguardando cliente',
            self::Converted => 'Projeto em andamento',
            self::Cancelled => 'Cancelada',
        };
    }

    /** O cliente pode editar dados e documentos da solicitação. */
    public function editableByClient(): bool
    {
        return in_array($this, [self::Submitted, self::NeedsInfo, self::InReview], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Converted, self::Cancelled], true);
    }
}
