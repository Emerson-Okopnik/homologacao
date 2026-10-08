<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $document_id
 * @property string $linkable_type
 * @property int $linkable_id
 * @property string $document_type
 * @property bool $is_current
 */
class DocumentLink extends Model
{
    use BelongsToTenant;

    protected $fillable = ['document_id', 'linkable_type', 'linkable_id', 'document_type', 'is_current', 'linked_by'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
