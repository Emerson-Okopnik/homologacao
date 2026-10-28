<?php

namespace App\Domain\Distributors\Models;

use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Projects\Models\ProjectVersion;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\TenantEntity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $payload_json
 * @property int $homologation_process_id
 * @property int|null $response_to_pending_item_id
 * @property CarbonInterface|null $submitted_at
 * @property-read ProcessDocument|null $receiptDocument
 */
class ExternalSubmission extends TenantEntity
{
    protected $table = 'submissions';

    /** @var list<string> */
    protected array $auditExclude = ['payload_json'];

    protected function casts(): array
    {
        return ['payload_json' => 'array', 'submitted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_intersect(array_keys($record->getDirty()), ['payload_json', 'project_version_id', 'request_hash', 'idempotency_key', 'kind', 'homologation_process_id', 'external_process_id', 'response_to_pending_item_id'])) {
                throw new DomainException('O conteúdo da submissão é imutável.', 'immutable_submission', 409);
            }
        });
        static::deleting(fn () => throw new DomainException('O histórico de submissões deve ser preservado.', 'immutable_submission', 409));
    }

    /** @return BelongsTo<ProjectVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class, 'project_version_id');
    }

    /** @return BelongsTo<ExternalProcess, $this> */
    public function externalProcess(): BelongsTo
    {
        return $this->belongsTo(ExternalProcess::class);
    }

    /** @return BelongsTo<ProcessDocument, $this> */
    public function receiptDocument(): BelongsTo
    {
        return $this->belongsTo(ProcessDocument::class, 'receipt_document_id');
    }

    public function markFailed(): void
    {
        if ($this->status === 'sent') {
            throw new DomainException('Um envio confirmado não pode ser marcado como falho.', 'submission_sent', 409);
        } $this->update(['status' => 'failed']);
    }
}
