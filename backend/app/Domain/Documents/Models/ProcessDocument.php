<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Versões são imutáveis: um novo upload cria uma nova linha e desmarca a anterior como corrente.
 *
 * @property int $id
 * @property string $uuid
 * @property int $homologation_process_id
 * @property string $document_type
 * @property int $version
 * @property bool $is_current
 * @property string $original_name
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $review_status
 * @property string|null $review_notes
 * @property int|null $uploaded_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 */
class ProcessDocument extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    /** @var list<string> */
    protected array $auditExclude = ['storage_path'];

    protected $fillable = [
        'homologation_process_id', 'document_type', 'version', 'is_current', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'sha256', 'review_status', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'version' => 'integer',
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<HomologationProcess, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(HomologationProcess::class, 'homologation_process_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
