<?php

namespace App\Domain\Rules\Models;

use App\Domain\Rules\RuleValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Base das regras versionadas e globais. Uma versão publicada não é editada:
 * mudanças geram nova versão (com nova vigência) e a anterior é encerrada.
 *
 * @property int $id
 * @property string $rule_code
 * @property int $version
 * @property string|null $distributor_code
 * @property int $priority
 * @property string $description
 * @property array<string, mixed> $conditions
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $source_reference
 * @property bool $active
 */
abstract class VersionedRule extends Model
{
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'active' => 'boolean',
            'priority' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (VersionedRule $rule): void {
            app(RuleValidator::class)->assertValidRule($rule);
        });
    }

    /**
     * Regras vigentes na data e aplicáveis à distribuidora (globais ou específicas).
     *
     * @param  Builder<static>  $query
     */
    public function scopeEffective(Builder $query, Carbon $at, ?string $distributorCode): void
    {
        $query->where('active', true)
            ->whereDate('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $at))
            ->where(fn ($q) => $q->whereNull('distributor_code')->when(
                $distributorCode !== null,
                fn ($q) => $q->orWhere('distributor_code', $distributorCode),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return collect($this->attributesToArray())->except(['created_at', 'updated_at'])->all();
    }
}
