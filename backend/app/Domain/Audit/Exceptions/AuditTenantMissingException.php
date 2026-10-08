<?php

namespace App\Domain\Audit\Exceptions;

use RuntimeException;

final class AuditTenantMissingException extends RuntimeException
{
    public function __construct(string $event)
    {
        parent::__construct("Não foi possível determinar o tenant para o evento de auditoria \"{$event}\".");
    }
}
