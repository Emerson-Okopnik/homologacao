<?php

namespace App\Domain\Homologations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Homologations\Enums\InspectionStatus;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property-read HomologationProcess $process
 * @property int $homologation_process_id
 * @property int $id
 * @property string $uuid
 * @property int $homologation_process_id
 * @property int|null $submission_id
 * @property int $sequence
 * @property InspectionStatus $status
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $scheduled_for
 * @property CarbonInterface|null $result_at
 * @property string|null $result_notes
 * @property int|null $pendency_id
 */
class Inspection extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $table = 'process_inspections';

    protected $fillable = ['homologation_process_id', 'sequence', 'requested_at', 'scheduled_for', 'requested_by'];

    protected $attributes = ['status' => 'REQUESTED'];

    protected function casts(): array
    {
        return [
            'status' => InspectionStatus::class,
            'requested_at' => 'datetime',
            'scheduled_for' => 'date',
            'result_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Resultado registrado (aprovação/reprovação) é histórico imutável.
        static::updating(function (Inspection $inspection): void {
            $original = InspectionStatus::from($inspection->getOriginal('status') instanceof InspectionStatus
                ? $inspection->getOriginal('status')->value
                : (string) $inspection->getOriginal('status'));

            if (! $original->isOpen()) {
                throw new LogicException('Uma vistoria encerrada não pode ser alterada.');
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<HomologationProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(HomologationProcess::class, 'homologation_process_id');
    }
}
