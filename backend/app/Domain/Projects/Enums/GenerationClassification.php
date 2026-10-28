<?php

namespace App\Domain\Projects\Enums;

enum GenerationClassification: string
{
    case Micro = 'MICRO';
    case MiniNonDispatchable = 'MINI_NON_DISPATCHABLE';
    case MiniDispatchable = 'MINI_DISPATCHABLE';

    public function label(): string
    {
        return match ($this) {
            self::Micro => 'Microgeração',
            self::MiniNonDispatchable => 'Minigeração (não despachável)',
            self::MiniDispatchable => 'Minigeração (despachável)',
        };
    }

    public function group(): string
    {
        return $this === self::Micro ? 'MICRO' : 'MINI';
    }
}
