<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Documents\Models\Document;
use App\Domain\Projects\Enums\ClientRequestStatus;
use App\Domain\Shared\Concerns\HasPublicUuid;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * Solicitação aberta pelo cliente (dono do sistema) pelo portal.
 *
 * @property int $id
 * @property string $uuid
 * @property int $client_id
 * @property int $consumer_unit_id
 * @property string $code
 * @property ClientRequestStatus $status
 * @property array<string, mixed> $system
 * @property list<array<string, mixed>> $messages
 * @property int|null $technical_responsible_id
 * @property int|null $solar_project_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $converted_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $created_at
 */
class ClientRequest extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasPublicUuid;

    protected $fillable = ['client_id', 'consumer_unit_id', 'code', 'system', 'submitted_by', 'submitted_at'];

    protected $attributes = ['messages' => '[]', 'status' => 'SUBMITTED'];

    protected function casts(): array
    {
        return [
            'status' => ClientRequestStatus::class,
            'system' => 'array',
            'messages' => 'array',
            'assigned_at' => 'datetime',
            'converted_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return BelongsTo<ConsumerUnit, $this> */
    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }

    /** @return BelongsTo<TechnicalResponsible, $this> */
    public function technicalResponsible(): BelongsTo
    {
        return $this->belongsTo(TechnicalResponsible::class);
    }

    /** @return BelongsTo<SolarProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(SolarProject::class, 'solar_project_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return MorphToMany<Document, $this> */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->withPivot(['document_type', 'is_current', 'tenant_id'])
            ->withTimestamps();
    }

    public function addMessage(string $from, string $author, string $text): void
    {
        $messages = $this->messages ?? [];
        $messages[] = ['at' => now()->toIso8601String(), 'from' => $from, 'author' => $author, 'text' => $text];
        $this->messages = $messages;
    }

    /**
     * Potências declaradas pelo cliente (módulos em kWp, inversores em kW).
     *
     * @return array{modules_kwp: float, inverters_kw: float}
     */
    public function declaredPowers(): array
    {
        $modules = collect($this->system['modules'] ?? [])->sum(fn ($m) => (float) ($m['power_w'] ?? 0) * (int) ($m['quantity'] ?? 0)) / 1000;
        $inverters = collect($this->system['inverters'] ?? [])->sum(fn ($i) => (float) ($i['power_kw'] ?? 0) * (int) ($i['quantity'] ?? 0));

        return ['modules_kwp' => round($modules, 3), 'inverters_kw' => round($inverters, 3)];
    }
}
