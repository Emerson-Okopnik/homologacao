<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Documents\Models\Document;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Enums\CompensationMode;
use App\Domain\Projects\Enums\GenerationClassification;
use App\Domain\Projects\Enums\ResponsibilityPurpose;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $client_id
 * @property int $consumer_unit_id
 * @property int|null $service_request_id
 * @property string $code
 * @property string $source_type
 * @property string $installed_power_kwp
 * @property string $inverter_power_kw
 * @property string $considered_power_kw
 * @property bool $has_battery
 * @property string|null $storage_energy_kwh
 * @property bool $has_dispatch_controller
 * @property bool $declared_dispatchable
 * @property bool $has_coupling_transformer
 * @property string|null $estimated_generation_kwh_month
 * @property CompensationMode $compensation_mode
 * @property string|null $compensation_method
 * @property GenerationClassification|null $classification
 * @property int|null $classification_decision_id
 * @property bool $fast_track_eligible
 * @property int|null $fast_track_decision_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
class SolarProject extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'consumer_unit_id', 'service_request_id', 'code', 'source_type',
        'installed_power_kwp', 'inverter_power_kw', 'considered_power_kw',
        'has_battery', 'storage_energy_kwh', 'has_dispatch_controller', 'declared_dispatchable', 'has_coupling_transformer',
        'estimated_generation_kwh_month', 'compensation_mode', 'compensation_method', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'has_battery' => 'boolean',
            'has_dispatch_controller' => 'boolean',
            'declared_dispatchable' => 'boolean',
            'has_coupling_transformer' => 'boolean',
            'fast_track_eligible' => 'boolean',
            'compensation_mode' => CompensationMode::class,
            'classification' => GenerationClassification::class,
            'installed_power_kwp' => 'decimal:3',
            'inverter_power_kw' => 'decimal:3',
            'considered_power_kw' => 'decimal:3',
            'storage_energy_kwh' => 'decimal:2',
            'estimated_generation_kwh_month' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<ConsumerUnit, $this> */
    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** @return BelongsToMany<EquipmentItem, $this> */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(EquipmentItem::class, 'project_equipment', 'solar_project_id', 'equipment_item_id')
            ->withPivot(['quantity', 'tenant_id'])
            ->withTimestamps();
    }

    /** @return HasMany<CompensationUnit, $this> */
    public function compensationUnits(): HasMany
    {
        return $this->hasMany(CompensationUnit::class);
    }

    /** @return HasMany<TechnicalResponsibility, $this> */
    public function responsibilities(): HasMany
    {
        return $this->hasMany(TechnicalResponsibility::class);
    }

    public function responsibility(ResponsibilityPurpose $purpose): ?TechnicalResponsibility
    {
        return $this->responsibilities->firstWhere('purpose', $purpose);
    }

    /** @return HasMany<FastTrackAcceptance, $this> */
    public function fastTrackAcceptances(): HasMany
    {
        return $this->hasMany(FastTrackAcceptance::class);
    }

    /** @return HasMany<RequirementWaiver, $this> */
    public function waivers(): HasMany
    {
        return $this->hasMany(RequirementWaiver::class);
    }

    /** @return HasMany<ProjectVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProjectVersion::class)->orderByDesc('version');
    }

    /** @return HasOne<HomologationProcess, $this> */
    public function process(): HasOne
    {
        return $this->hasOne(HomologationProcess::class);
    }

    /** @return MorphToMany<Document, $this> */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->withPivot(['document_type', 'is_current', 'tenant_id'])
            ->withTimestamps();
    }

    /** O projeto só pode ser alterado enquanto o processo estiver em preparação ou correção. */
    public function isEditable(): bool
    {
        $process = $this->process;

        return $process === null || ($process->isActive() && $process->stage->allowsProjectEdit());
    }
}
