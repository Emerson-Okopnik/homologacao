<?php

namespace App\Providers;

use App\Domain\Audit\Redactor;
use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Homologations\Models\ConnectionEvent;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Inspection;
use App\Domain\Homologations\Models\ProjectExecution;
use App\Domain\Homologations\WorkflowDefinition;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Secrets\EnvSecretProvider;
use App\Infrastructure\Secrets\SecretProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: um contexto novo por request/job (inclusive em Octane/queue workers).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(WorkflowDefinition::class);
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
        Date::useClass(CarbonImmutable::class);
        Relation::morphMap([
            'project' => SolarProject::class,
            'equipment' => EquipmentItem::class,
            'execution' => ProjectExecution::class,
            'inspection' => Inspection::class,
            'connection_event' => ConnectionEvent::class,
            'process' => HomologationProcess::class,
            'client_request' => \App\Domain\Projects\Models\ClientRequest::class,
        ]);
    }
}
