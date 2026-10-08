<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonInterface|null $validated_at */
class ChecklistItem extends TenantEntity
{
    protected $table = 'process_checklist';

    protected function casts(): array
    {
        return ['applicable' => 'boolean', 'validated_at' => 'datetime'];
    }

    /** @return BelongsTo<Requirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    /** @return BelongsTo<ProcessDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ProcessDocument::class, 'document_id');
    }

    public function approve(int $actorId, ?string $notes = null): void
    {
        $this->update(['status' => 'aprovado', 'validated_by' => $actorId, 'validated_at' => now(), 'notes' => $notes]);
    }

    public function reject(int $actorId, string $notes): void
    {
        $this->update(['status' => 'reprovado', 'validated_by' => $actorId, 'validated_at' => now(), 'notes' => $notes]);
    }
}
