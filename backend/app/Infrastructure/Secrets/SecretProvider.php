<?php

namespace App\Infrastructure\Secrets;

/**
 * Resolve referências de segredo (ex.: credenciais CELESC) em tempo de uso.
 * O banco guarda apenas a referência; o valor nunca é persistido, logado ou auditado.
 */
interface SecretProvider
{
    /**
     * @throws SecretNotFoundException
     */
    public function get(string $reference): string;

    public function has(string $reference): bool;
}
