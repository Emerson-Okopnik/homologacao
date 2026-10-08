<?php

namespace App\Domain\Projects;

use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Exceptions\DomainException;

/** Um sistema de armazenamento agrega zero ou vários conjuntos de baterias. */
final class StorageSystem
{
    public function __construct(private readonly SolarProject $project) {}

    public function energyKwh(): float
    {
        return $this->project->storage->sum(fn ($row) => (float) $row->energy_kwh * $row->quantity);
    }

    public function powerKw(): float
    {
        return $this->project->storage->sum(fn ($row) => (float) $row->power_kw * $row->quantity);
    }

    public function validateStrategy(): void
    {
        if ($this->project->has_battery && $this->project->storage->isEmpty()) {
            throw new DomainException('Cadastre as baterias do sistema de armazenamento informado no projeto.', 'storage_incomplete');
        }
        foreach ($this->project->storage as $row) {
            if (! $row->operating_strategy || (float) $row->energy_kwh <= 0 || (float) $row->power_kw <= 0) {
                throw new DomainException('Complete a capacidade, potência e estratégia do armazenamento.', 'storage_incomplete');
            }
        }
    }
}
