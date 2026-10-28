<?php

namespace App\Domain\Rules\Models;

use App\Domain\Rules\Enums\DecisionType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Registro imutável de uma decisão: regra (com snapshot), fatos e resultado.
 *
 * @property int $id
 * @property DecisionType $decision_type
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $rule_code
 * @property int|null $rule_version
 * @property array<string, mixed>|null $rule_snapshot
 * @property array<string, mixed> $facts
 * @property array<string, mixed> $result
 * @property Carbon $created_at
 */
class RuleDecision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'decision_type', 'subject_type', 'subject_id', 'rule_table', 'rule_id',
        'rule_code', 'rule_version', 'rule_snapshot', 'facts', 'result',
    ];

    protected function casts(): array
    {
        return [
            'decision_type' => DecisionType::class,
            'rule_snapshot' => 'array',
            'facts' => 'array',
            'result' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Decisões de regra são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Decisões de regra são imutáveis.'));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $result
     */
    public static function record(DecisionType $type, Model $subject, ?VersionedRule $rule, array $facts, array $result): self
    {
        return self::create([
            'decision_type' => $type,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'rule_table' => $rule?->getTable(),
            'rule_id' => $rule?->getKey(),
            'rule_code' => $rule?->rule_code,
            'rule_version' => $rule?->version,
            'rule_snapshot' => $rule?->snapshot(),
            'facts' => $facts,
            'result' => $result,
        ]);
    }
}
