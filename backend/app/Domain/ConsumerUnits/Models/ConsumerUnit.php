<?php

namespace App\Domain\ConsumerUnits\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Clients\Models\Client;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $client_id
 * @property int $distributor_id
 * @property string $number
 * @property string $street
 * @property string|null $address_number
 * @property string|null $complement
 * @property string|null $district
 * @property string $city
 * @property string $state
 * @property string|null $zip
 * @property string $voltage_class
 * @property string $supply_type
 * @property string|null $installed_load_kw
 * @property string|null $contracted_demand_kw
 * @property int|null $breaker_a
 * @property bool $active
 */
class ConsumerUnit extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = [
        'client_id', 'distributor_id', 'number', 'street', 'address_number', 'complement', 'district',
        'city', 'state', 'zip', 'voltage_class', 'supply_type', 'installed_load_kw', 'contracted_demand_kw',
        'breaker_a', 'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'installed_load_kw' => 'decimal:2',
            'contracted_demand_kw' => 'decimal:2',
            'breaker_a' => 'integer',
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
     * @return BelongsTo<Distributor, $this>
     */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    public function fullAddress(): string
    {
        return trim(sprintf(
            '%s%s%s, %s/%s',
            $this->street,
            $this->address_number ? ', '.$this->address_number : '',
            $this->district ? ' - '.$this->district : '',
            $this->city,
            $this->state,
        ));
    }
}
