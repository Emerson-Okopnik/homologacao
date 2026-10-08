<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Homologations\Enums\NetworkWorkStatus;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Homologations\Enums\WorkflowStage;
use App\Domain\Projects\Models\ProjectVersion;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $solar_project_id
 * @property int $distributor_id
 * @property int|null $assigned_user_id
 * @property int|null $project_version_id
 * @property string $code
 * @property ProcessStatus $status
 * @property WorkflowStage $stage
 * @property NetworkWorkStatus $network_work_status
 * @property string|null $protocol_number
 * @property Carbon|null $stage_changed_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 */
class HomologationProcess extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['solar_project_id', 'distributor_id', 'assigned_user_id', 'code', 'protocol_number'];

    protected $attributes = [
        'status' => 'ACTIVE',
        'stage' => 'PREPARATION',
        'network_work_status' => 'UNDER_ANALYSIS',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProcessStatus::class,
            'stage' => WorkflowStage::class,
            'network_work_status' => NetworkWorkStatus::class,
            'stage_changed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ProcessStatus::Active;
    }

    /** @return BelongsTo<SolarProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(SolarProject::class, 'solar_project_id');
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return BelongsTo<ProjectVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class, 'project_version_id');
    }

    /** @return HasMany<ProcessPendency, $this> */
    public function pendencies(): HasMany
    {
        return $this->hasMany(ProcessPendency::class)->latest();
    }

    /** @return HasMany<ProcessPendency, $this> */
    public function openPendencies(): HasMany
    {
        return $this->hasMany(ProcessPendency::class)->where('status', 'aberta');
    }

    /** @return HasMany<ProcessInteraction, $this> */
    public function interactions(): HasMany
    {
        return $this->hasMany(ProcessInteraction::class)->latest('occurred_at');
    }

    /** @return HasOne<ProjectExecution, $this> */
    public function execution(): HasOne
    {
        return $this->hasOne(ProjectExecution::class);
    }

    /** @return HasMany<Inspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class)->orderByDesc('sequence');
    }

    /** @return HasMany<ConnectionEvent, $this> */
    public function connectionEvents(): HasMany
    {
        return $this->hasMany(ConnectionEvent::class)->orderByDesc('occurred_at');
    }

    /** @return HasMany<ProcessDeadline, $this> */
    public function deadlines(): HasMany
    {
        return $this->hasMany(ProcessDeadline::class)->latest('id');
    }

    /** @return HasMany<TimelineEvent, $this> */
    public function timeline(): HasMany
    {
        return $this->hasMany(TimelineEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
