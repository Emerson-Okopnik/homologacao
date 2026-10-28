<?php

namespace App\Domain\Homologations\Enums;

enum ConnectionEventType: string
{
    case MeterInstalled = 'METER_INSTALLED';
    case SystemConnected = 'SYSTEM_CONNECTED';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::MeterInstalled => 'Medidor bidirecional instalado',
            self::SystemConnected => 'Sistema conectado à rede',
            self::Other => 'Outro evento',
        };
    }
}
