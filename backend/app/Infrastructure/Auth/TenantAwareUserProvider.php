<?php

namespace App\Infrastructure\Auth;

use App\Domain\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * A identificação do usuário (login, sessão, reset de senha) acontece antes de existir
     * um tenant resolvido. E-mail é globalmente único, então a busca é inequívoca.
     *
     * @template TModel of Model
     *
     * @param  TModel|null  $model
     * @return Builder<TModel>
     */
    protected function newModelQuery($model = null): Builder
    {
        /** @var Builder<TModel> $query */
        $query = parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);

        return $query;
    }
}
