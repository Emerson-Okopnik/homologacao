<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $requirement_code
 * @property string $reason
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
class RequirementWaiver extends Model
{
    use Auditable;
    use BelongsToTenant;

    protected $fillable = ['solar_project_id', 'requirement_code', 'reason', 'evidence_document_id', 'authorized_by'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
