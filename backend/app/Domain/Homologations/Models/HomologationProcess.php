<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Distributors\Models\ExternalProcess;
use App\Domain\Distributors\Models\ExternalSubmission;
use App\Domain\Distributors\Models\IntegrationEvent;
use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Enums\ProcessStatus;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property CarbonInterface|null $status_changed_at
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $connected_at
 * @property CarbonInterface|null $due_date
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read WorkflowStage|null $currentStage
 * @property-read ExternalProcess|null $externalProcess
 */
class HomologationProcess extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['solar_project_id', 'distributor_id', 'assigned_user_id', 'code', 'status', 'protocol_number', 'due_date', 'current_stage_id', 'priority', 'opened_at', 'completed_at', 'process_type'];

    protected function casts(): array
    {
        return [
            'status' => ProcessStatus::class,
            'status_changed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'connected_at' => 'datetime',
            'due_date' => 'date',
            'opened_at' => 'datetime', 'completed_at' => 'datetime',
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

    /** @return BelongsTo<WorkflowStage, $this> */
    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    /** @return HasMany<ProcessStageHistory, $this> */
    public function stageHistory(): HasMany
    {
        return $this->hasMany(ProcessStageHistory::class)->orderBy('id');
    }

    /** @return HasMany<ProcessAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProcessAssignment::class)->orderByDesc('id');
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class);
    }

    /** @return HasOne<ExternalProcess, $this> */
    public function externalProcess(): HasOne
    {
        return $this->hasOne(ExternalProcess::class);
    }

    /** @return HasMany<ExternalSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(ExternalSubmission::class)->orderByDesc('id');
    }

    /** @return HasMany<IntegrationEvent, $this> */
    public function integrationEvents(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class)->orderByDesc('id');
    }
}
