<?php

namespace App\Domain\Distributors\Models;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;

/**
 * @property CarbonInterface $occurred_at
 * @property array<string, mixed>|null $response_payload_json
 */
class IntegrationEvent extends TenantEntity
{
    /** @var list<string> */
    protected array $auditExclude = ['response_payload_json'];

    protected function casts(): array
    {
        return ['response_payload_json' => 'array', 'success' => 'boolean', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Eventos de integração são imutáveis.', 'immutable_event', 409));
        static::deleting(fn () => throw new DomainException('Eventos de integração são imutáveis.', 'immutable_event', 409));
    }
}
