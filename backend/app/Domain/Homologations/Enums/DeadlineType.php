<?php

namespace App\Domain\Homologations\Enums;

enum DeadlineType: string
{
    case AccessOpinion = 'ACCESS_OPINION';
    case Inspection = 'INSPECTION';

    public function label(): string
    {
        return match ($this) {
            self::AccessOpinion => 'Parecer de acesso',
            self::Inspection => 'Vistoria',
        };
    }
}
