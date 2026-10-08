<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Enums\CompensationMode;
use App\Domain\Projects\Enums\GenerationClassification;
use App\Domain\Projects\Enums\ResponsibilityPurpose;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property CompensationMode $compensation_mode
 * @property GenerationClassification|null $classification
 * @property int|null $service_request_id
 * @property int|null $classification_decision_id
 * @property int|null $fast_track_decision_id
 * @property string $source_type
 * @property string $considered_power_kw
 * @property string|null $compensation_method
 * @property string|null $storage_energy_kwh
 * @property bool $has_dispatch_controller
 * @property bool $declared_dispatchable
 * @property bool $has_coupling_transformer
 * @property bool $fast_track_eligible
 * @property string $uuid
 * @property int $tenant_id
 * @property int $client_id
 * @property int $consumer_unit_id
 * @property int|null $technical_responsible_id
 * @property string $code
 * @property string $generation_type
 * @property string $modality
 * @property string $installed_power_kwp
 * @property string $inverter_power_kw
 * @property bool $has_battery
 * @property string|null $estimated_generation_kwh_month
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read CompensationConfig|null $compensation
 * @property-read ProjectConnectionData|null $connectionData
 */
class SolarProject extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;
    use SoftDeletes;

    public const MODALITIES = [
        'autoconsumo_local' => 'Autoconsumo local',
        'autoconsumo_remoto' => 'Autoconsumo remoto',
        'geracao_compartilhada' => 'Geração compartilhada',
        'multiplas_uc' => 'Múltiplas unidades consumidoras',
    ];

    /** Limite de microgeração em kW (Lei 14.300/2022). */
    public const MICRO_LIMIT_KW = 75;

    protected $fillable = [
        'client_id', 'consumer_unit_id', 'technical_responsible_id', 'code', 'generation_type', 'modality',
        'installed_power_kwp', 'inverter_power_kw', 'has_battery', 'estimated_generation_kwh_month', 'notes', 'created_by',
        'name', 'status', 'installation_type', 'service_request_id', 'source_type', 'considered_power_kw', 'storage_energy_kwh', 'has_dispatch_controller', 'declared_dispatchable', 'has_coupling_transformer', 'compensation_mode', 'compensation_method', 'classification', 'classification_decision_id', 'fast_track_eligible', 'fast_track_decision_id',
    ];

    protected function casts(): array
    {
        return [
            'installed_power_kwp' => 'decimal:3',
            'inverter_power_kw' => 'decimal:3',
            'estimated_generation_kwh_month' => 'decimal:2',
            'has_battery' => 'boolean', 'compensation_mode' => CompensationMode::class, 'classification' => GenerationClassification::class, 'has_dispatch_controller' => 'boolean', 'declared_dispatchable' => 'boolean', 'has_coupling_transformer' => 'boolean', 'fast_track_eligible' => 'boolean', 'considered_power_kw' => 'decimal:3', 'storage_energy_kwh' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ConsumerUnit, $this>
     */
    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }

    /**
     * @return BelongsTo<TechnicalResponsible, $this>
     */
    public function technicalResponsible(): BelongsTo
    {
        return $this->belongsTo(TechnicalResponsible::class);
    }

    /**
     * @return BelongsToMany<EquipmentItem, $this>
     */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(EquipmentItem::class, 'project_equipment', 'solar_project_id', 'equipment_item_id')
            ->withPivot(['quantity', 'tenant_id'])
            ->withTimestamps();
    }

    /**
     * @return HasOne<HomologationProcess, $this>
     */
    public function process(): HasOne
    {
        return $this->hasOne(HomologationProcess::class)->latestOfMany();
    }

    /** @return HasMany<HomologationProcess, $this> */
    public function processes(): HasMany
    {
        return $this->hasMany(HomologationProcess::class);
    }

    /** @return HasMany<ProjectVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProjectVersion::class)->orderByDesc('version');
    }

    /** @return HasOne<ProjectConnectionData, $this> */
    public function connectionData(): HasOne
    {
        return $this->hasOne(ProjectConnectionData::class);
    }

    /** @return HasMany<SolarArray, $this> */
    public function arrays(): HasMany
    {
        return $this->hasMany(SolarArray::class);
    }

    /** @return HasMany<ProjectInverter, $this> */
    public function inverters(): HasMany
    {
        return $this->hasMany(ProjectInverter::class);
    }

    /** @return HasMany<ProjectStorage, $this> */
    public function storage(): HasMany
    {
        return $this->hasMany(ProjectStorage::class);
    }

    /** @return HasOne<CompensationConfig, $this> */
    public function compensation(): HasOne
    {
        return $this->hasOne(CompensationConfig::class);
    }

    /** @return HasMany<ResponsibilityTerm, $this> */
    public function responsibilityTerms(): HasMany
    {
        return $this->hasMany(ResponsibilityTerm::class);
    }

    /** @return HasMany<ProcessDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ProcessDocument::class);
    }

    public function assertEditable(): void
    {
        if ($this->processes()->whereNotIn('status', ['rascunho', 'em_preparacao', 'pendencia_distribuidora', 'reprovado', 'cancelado', 'conectado'])->exists()) {
            throw new DomainException('Retorne o processo à preparação antes de alterar os dados do projeto.', 'project_locked', 409);
        }
    }

    /**
     * Potência de acesso = menor valor entre potência dos módulos e dos inversores.
     */
    public function accessPowerKw(): float
    {
        $modules = (float) $this->installed_power_kwp;
        $inverters = (float) $this->inverter_power_kw;

        if ($inverters <= 0) {
            return $modules;
        }

        return min($modules, $inverters);
    }

    public static function classify(float $accessPowerKw): string
    {
        return $accessPowerKw <= self::MICRO_LIMIT_KW ? 'micro' : 'mini';
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
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

    public function isEditable(): bool
    {
        return ! $this->processes()->whereNotIn('status', ['rascunho', 'em_preparacao', 'pendencia_distribuidora', 'reprovado', 'cancelado', 'conectado'])->exists();
    }
}
