<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Aplicado a toda entidade de negócio com coluna tenant_id.
 *
 * - Filtra todas as queries pelo tenant atual (TenantScope).
 * - Preenche tenant_id na criação a partir do contexto.
 * - Impede gravar um registro com tenant diferente do contexto ou trocar o tenant de um registro.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $context->id() ?? throw new TenantNotResolvedException);

                return;
            }

            if ($context->has() && (int) $model->getAttribute('tenant_id') !== $context->id()) {
                throw new LogicException('Tentativa de criar registro em tenant diferente do contexto atual.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('O tenant de um registro não pode ser alterado.');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
