<?php

namespace App\Domain\Projects\Enums;

enum CompensationMode: string
{
    case LocalSelfConsumption = 'LOCAL_SELF_CONSUMPTION';
    case RemoteSelfConsumption = 'REMOTE_SELF_CONSUMPTION';
    case SharedGeneration = 'SHARED_GENERATION';
    case MultipleUnits = 'MULTIPLE_UNITS';

    public function label(): string
    {
        return match ($this) {
            self::LocalSelfConsumption => 'Autoconsumo local',
            self::RemoteSelfConsumption => 'Autoconsumo remoto',
            self::SharedGeneration => 'Geração compartilhada',
            self::MultipleUnits => 'Múltiplas unidades consumidoras',
        };
    }

    /** Modalidades em que excedentes podem ir para outras UCs. */
    public function allowsAllocation(): bool
    {
        return $this !== self::LocalSelfConsumption;
    }
}
