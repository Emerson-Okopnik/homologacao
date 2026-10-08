<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Regra de negócio violada. A mensagem é segura para exibir ao usuário.
 */
class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'domain_rule_violated',
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
