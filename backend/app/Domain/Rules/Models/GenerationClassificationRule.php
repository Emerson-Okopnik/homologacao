<?php

namespace App\Domain\Rules\Models;

/**
 * @property string $classification
 */
class GenerationClassificationRule extends VersionedRule
{
    protected $fillable = [
        'rule_code', 'version', 'distributor_code', 'priority', 'description', 'conditions',
        'effective_from', 'effective_to', 'source_reference', 'active', 'classification',
    ];
}
