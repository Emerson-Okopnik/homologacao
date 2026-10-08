<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
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
    ];

    protected function casts(): array
    {
        return [
            'installed_power_kwp' => 'decimal:3',
            'inverter_power_kw' => 'decimal:3',
            'estimated_generation_kwh_month' => 'decimal:2',
            'has_battery' => 'boolean',
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
        return $this->hasOne(HomologationProcess::class);
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
}
