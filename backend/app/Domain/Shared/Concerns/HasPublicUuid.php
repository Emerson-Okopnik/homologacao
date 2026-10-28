<?php

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * IDs numéricos ficam internos; a API expõe e resolve rotas somente pelo UUID.
 */
trait HasPublicUuid
{
    use HasUuids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
