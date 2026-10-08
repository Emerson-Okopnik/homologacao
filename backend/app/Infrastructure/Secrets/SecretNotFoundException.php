<?php

namespace App\Infrastructure\Secrets;

use RuntimeException;

final class SecretNotFoundException extends RuntimeException
{
    public function __construct(string $reference)
    {
        // A mensagem cita apenas a referência, nunca valores.
        parent::__construct("Segredo não configurado ou não permitido: {$reference}");
    }
}
