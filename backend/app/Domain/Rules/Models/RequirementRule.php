<?php

namespace App\Domain\Rules\Models;

use App\Domain\Rules\Enums\RequirementPhase;

/**
 * Requisito condicional. `conditions` define quando o requisito se aplica;
 * para requisitos do tipo FACT, `satisfied_when` define quando está atendido.
 * Requisitos do tipo DOCUMENT são atendidos por documento aprovado do tipo informado.
 *
 * @property RequirementPhase $phase
 * @property string $kind
 * @property string|null $document_type
 * @property array<string, mixed>|null $satisfied_when
 * @property string $outcome
 * @property string $label
 * @property string $reason
 * @property bool $waivable
 */
class RequirementRule extends VersionedRule
{
    protected $fillable = [
        'rule_code', 'version', 'distributor_code', 'priority', 'description', 'conditions',
        'effective_from', 'effective_to', 'source_reference', 'active',
        'phase', 'kind', 'document_type', 'satisfied_when', 'outcome', 'label', 'reason', 'waivable',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'phase' => RequirementPhase::class,
            'satisfied_when' => 'array',
            'waivable' => 'boolean',
        ];
    }
}
