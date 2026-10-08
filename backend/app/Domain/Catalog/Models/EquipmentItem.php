<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property Pivot $pivot
 * @property string $uuid
 * @property string $type
 * @property string $manufacturer
 * @property string $model
 * @property string|null $power_w
 * @property string|null $energy_kwh
 * @property string|null $efficiency
 * @property string|null $certification
 * @property array<string, mixed>|null $specs
 * @property bool $active
 */
class EquipmentItem extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    public const TYPES = ['module', 'inverter', 'battery'];

    protected $table = 'equipment_catalog';

    protected $fillable = ['type', 'manufacturer', 'model', 'power_w', 'energy_kwh', 'efficiency', 'certification', 'specs', 'active'];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'active' => 'boolean',
            'power_w' => 'decimal:2',
            'energy_kwh' => 'decimal:2',
            'efficiency' => 'decimal:2',
        ];
    }

    public function label(): string
    {
        return "{$this->manufacturer} {$this->model}";
    }
}
