<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Protocolo inicial aberto na distribuidora (solicitação de serviço).
 *
 * @property int $id
 * @property string $protocol_number
 * @property \Illuminate\Support\Carbon|null $opened_at
 */
class ServiceRequest extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['consumer_unit_id', 'distributor_id', 'protocol_number', 'opened_at', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['opened_at' => 'date'];
    }

    /** @return BelongsTo<ConsumerUnit, $this> */
    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
