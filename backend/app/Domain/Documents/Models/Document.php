<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Arquivo imutável. Uma nova versão gera outro Document (supersedes_document_id).
 *
 * @property int $id
 * @property string $uuid
 * @property string $document_type
 * @property int $version
 * @property int|null $supersedes_document_id
 * @property string $original_name
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $review_status
 * @property string|null $review_notes
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 */
class Document extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = [
        'document_type', 'version', 'supersedes_document_id', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'sha256', 'review_status', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'size_bytes' => 'integer', 'version' => 'integer'];
    }

    /** @return HasMany<DocumentLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
