<?php

namespace App\Domain\Shared;

use Illuminate\Database\Eloquent\Model;

/**
 * Gera códigos legíveis (ex.: PRJ-2026-0001) sequenciais por tenant e ano.
 * Deve ser chamado dentro de transação; a unique (tenant_id, code) protege contra corrida.
 */
final class SequentialCode
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function next(string $model, string $prefix): string
    {
        $base = sprintf('%s-%s-', $prefix, now()->format('Y'));

        $last = $model::query()
            ->where('code', 'like', $base.'%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        $sequence = $last ? ((int) substr((string) $last, strlen($base))) + 1 : 1;

        return $base.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
