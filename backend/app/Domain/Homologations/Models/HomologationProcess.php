<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $solar_project_id
 * @property int $distributor_id
 * @property int|null $assigned_user_id
 * @property string $code
 * @property ProcessStatus $status
 * @property string|null $protocol_number
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $due_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HomologationProcess extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['solar_project_id', 'distributor_id', 'assigned_user_id', 'code', 'status', 'protocol_number', 'due_date'];

    protected function casts(): array
    {
        return [
            'status' => ProcessStatus::class,
            'status_changed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'connected_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SolarProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(SolarProject::class, 'solar_project_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Distributor, $this>
     */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<ProcessStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(ProcessStatusHistory::class)->latest('id');
    }

    /**
     * @return HasMany<ProcessDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ProcessDocument::class);
    }

    /**
     * @return HasMany<ProcessDocument, $this>
     */
    public function currentDocuments(): HasMany
    {
        return $this->hasMany(ProcessDocument::class)->where('is_current', true);
    }

    /**
     * @return HasMany<ProcessPendency, $this>
     */
    public function pendencies(): HasMany
    {
        return $this->hasMany(ProcessPendency::class)->latest('id');
    }

    /**
     * @return HasMany<ProcessPendency, $this>
     */
    public function openPendencies(): HasMany
    {
        return $this->hasMany(ProcessPendency::class)->where('status', 'aberta');
    }

    /**
     * @return HasMany<ProcessInteraction, $this>
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(ProcessInteraction::class)->latest('occurred_at');
    }
}
