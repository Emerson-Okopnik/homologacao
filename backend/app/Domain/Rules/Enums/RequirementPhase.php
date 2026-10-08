<?php

namespace App\Domain\Rules\Enums;

enum RequirementPhase: string
{
    case Submission = 'SUBMISSION';
    case InspectionRequest = 'INSPECTION_REQUEST';
    case Completion = 'COMPLETION';

    public function label(): string
    {
        return match ($this) {
            self::Submission => 'Envio à distribuidora',
            self::InspectionRequest => 'Solicitação de vistoria',
            self::Completion => 'Conclusão',
        };
    }
}
