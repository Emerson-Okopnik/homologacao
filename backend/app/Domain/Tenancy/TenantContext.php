<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use Closure;

/**
 * Tenant ativo da execução atual (request, job ou comando).
 * Registrado como singleton com escopo de request (scoped) no container.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new Exceptions\TenantNotResolvedException;
    }

    /**
     * Executa um bloco no contexto de um tenant (seeders, jobs, testes) e restaura o anterior.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
