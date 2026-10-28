<?php

namespace App\Domain\Projects\Enums;

enum ResponsibilityPurpose: string
{
    case Project = 'PROJECT';
    case Execution = 'EXECUTION';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Projeto',
            self::Execution => 'Execução',
        };
    }

    public function artDocumentType(): string
    {
        return $this === self::Project ? 'ART_PROJECT' : 'ART_EXECUTION';
    }
}
