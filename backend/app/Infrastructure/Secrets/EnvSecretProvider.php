<?php

namespace App\Infrastructure\Secrets;

final class EnvSecretProvider implements SecretProvider
{
    /**
     * @param  array<string, string|null>  $allowlist  referência => valor, vindo de config/secrets.php
     */
    public function __construct(private readonly array $allowlist) {}

    public function get(string $reference): string
    {
        $value = $this->allowlist[$reference] ?? null;

        if (! is_string($value) || $value === '') {
            throw new SecretNotFoundException($reference);
        }

        return $value;
    }

    public function has(string $reference): bool
    {
        $value = $this->allowlist[$reference] ?? null;

        return is_string($value) && $value !== '';
    }
}
