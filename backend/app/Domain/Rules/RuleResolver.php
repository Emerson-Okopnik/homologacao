<?php

namespace App\Domain\Rules;

use App\Domain\Rules\Models\VersionedRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seleciona, por rule_code, a versão aplicável: vigente na data, específica da
 * distribuidora antes da global, maior prioridade e, por fim, maior versão.
 */
final class RuleResolver
{
    /**
     * @template T of VersionedRule
     *
     * @param  class-string<T>  $model
     * @param  (callable(\Illuminate\Database\Eloquent\Builder<T>): void)|null  $filter
     * @return Collection<int, T>
     */
    public function resolve(string $model, Carbon $at, ?string $distributorCode, ?callable $filter = null): Collection
    {
        $query = $model::query()->effective($at, $distributorCode);

        if ($filter !== null) {
            $filter($query);
        }

        return $query->get()
            ->groupBy('rule_code')
            ->map(fn (Collection $versions) => $versions->sort(function (VersionedRule $a, VersionedRule $b): int {
                return [$b->distributor_code !== null, $b->priority, $b->version]
                    <=> [$a->distributor_code !== null, $a->priority, $a->version];
            })->first())
            ->sortByDesc('priority')
            ->values();
    }
}
