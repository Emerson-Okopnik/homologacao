<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Shared\TenantEntity;

/** @property array{next?: list<string>} $next_stage_rule_json */
class WorkflowStage extends TenantEntity
{
    protected $table = 'workflow_stages';

    protected function casts(): array
    {
        return ['next_stage_rule_json' => 'array', 'active' => 'boolean'];
    }

    public function canTransitionTo(self $target): bool
    {
        return $target->active && in_array($target->code, $this->next_stage_rule_json['next'] ?? [], true);
    }
}
