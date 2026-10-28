<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\Document;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property-read HomologationProcess $process
 * @property int $homologation_process_id
 * @property int $id
 * @property string $uuid
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface $completed_at
 * @property string|null $notes
 */
class ProjectExecution extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['homologation_process_id', 'started_at', 'completed_at', 'notes', 'reported_by'];

    protected function casts(): array
    {
        return ['started_at' => 'date', 'completed_at' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return MorphToMany<Document, $this> */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')->withPivot(['document_type', 'is_current']);
    }

    /** @return BelongsTo<HomologationProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(HomologationProcess::class, 'homologation_process_id');
    }
}
