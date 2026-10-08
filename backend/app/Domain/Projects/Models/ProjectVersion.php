<?php

namespace App\Domain\Projects\Models;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;

/**
 * @property array<string, mixed> $snapshot_json
 * @property int $solar_project_id
 * @property CarbonInterface $frozen_at
 * @property string $reason
 */
class ProjectVersion extends TenantEntity
{
    /** @return Attribute<array<string, mixed>, array<string, mixed>> */
    protected function snapshot(): Attribute
    {
        return Attribute::make(get: fn () => $this->snapshot_json, set: fn ($value) => ['snapshot_json' => json_encode($value, JSON_THROW_ON_ERROR), 'status' => 'frozen', 'frozen_at' => now()]);
    }

    /** @return Attribute<string, string> */
    protected function reason(): Attribute
    {
        return Attribute::make(get: fn () => $this->change_reason, set: fn ($value) => ['change_reason' => $value]);
    }

    /** @var list<string> */
    protected array $auditExclude = ['snapshot_json'];

    protected function casts(): array
    {
        return ['snapshot_json' => 'array', 'version' => 'integer', 'frozen_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $version) => $version->assertMutable());
        static::deleting(fn (self $version) => $version->assertMutable());
    }

    private function assertMutable(): void
    {
        if ($this->getOriginal('status') === 'frozen') {
            throw new DomainException('A versão congelada não pode ser alterada ou excluída.', 'frozen_version', 409);
        }
    }

    /** @return BelongsTo<SolarProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(SolarProject::class, 'solar_project_id');
    }

    /** @return BelongsToMany<ProcessDocument, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(ProcessDocument::class, 'project_version_documents');
    }

    public function freeze(): self
    {
        if ($this->status !== 'frozen') {
            $this->update(['status' => 'frozen', 'frozen_at' => now()]);
        }

        return $this;
    }

    /** @return list<array{field: string, before: mixed, after: mixed}> */
    public function compareTo(self $other): array
    {
        $before = Arr::dot($other->snapshot_json);
        $after = Arr::dot($this->snapshot_json);
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[] = ['field' => $key, 'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
            }
        }

        return $changes;
    }
}
