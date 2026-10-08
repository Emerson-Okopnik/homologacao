<?php

namespace App\Providers;

use App\Domain\Audit\Redactor;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Secrets\EnvSecretProvider;
use App\Infrastructure\Secrets\SecretProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: um contexto novo por request/job (inclusive em Octane/queue workers).
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(Redactor::class);

        $this->app->singleton(SecretProvider::class, fn () => match (config('secrets.driver')) {
            'env' => new EnvSecretProvider((array) config('secrets.refs', [])),
            default => throw new InvalidArgumentException('Driver de secrets não suportado: '.config('secrets.driver')),
        });
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();
        Date::useClass(\Carbon\CarbonImmutable::class);
    }
}
