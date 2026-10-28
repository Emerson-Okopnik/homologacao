<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends ProcessDocument
{
    protected $table = 'process_documents';

    /** @return HasMany<DocumentLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class, 'document_id');
    }
}
