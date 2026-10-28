<?php

namespace App\Domain\Rules\Models;

use App\Domain\Homologations\Enums\DeadlineType;

/**
 * @property DeadlineType $deadline_type
 * @property int $days
 * @property string $day_count
 */
class DeadlineRule extends VersionedRule
{
    protected $fillable = [
        'rule_code', 'version', 'distributor_code', 'priority', 'description', 'conditions',
        'effective_from', 'effective_to', 'source_reference', 'active',
        'deadline_type', 'days', 'day_count',
    ];

    protected function casts(): array
    {
        return [...parent::casts(), 'deadline_type' => DeadlineType::class, 'days' => 'integer'];
    }
}
