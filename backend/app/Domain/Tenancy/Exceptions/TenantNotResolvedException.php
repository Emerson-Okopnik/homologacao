<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class TenantNotResolvedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Nenhum tenant resolvido para a operação atual.');
    }
}
