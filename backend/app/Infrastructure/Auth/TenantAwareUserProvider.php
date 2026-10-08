<?php

namespace App\Infrastructure\Auth;

use App\Domain\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

final class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * A identificação do usuário (login, sessão, reset de senha) acontece antes de existir
     * um tenant resolvido. E-mail é globalmente único, então a busca é inequívoca.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     */
    protected function newModelQuery($model = null): Builder
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
