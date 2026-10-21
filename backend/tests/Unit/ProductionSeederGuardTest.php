<?php

use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Actions\ProvisionTenantRoles;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TestDataSeeder;
use Illuminate\Support\Facades\DB;

it('bloqueia seed demonstrativo em producao antes de resolver dependencias ou consultar o banco', function (string $seeder): void {
    $this->app->instance('env', 'production');
    DB::shouldReceive('connection')->never();
    $context = app(TenantContext::class);

    foreach ([TenantContext::class, ProvisionTenantRoles::class] as $dependency) {
        $this->app->bind($dependency, fn () => throw new LogicException('Dependência do seeder resolvida antes do bloqueio.'));
    }

    try {
        $instance = app($seeder)->setContainer($this->app);

        expect(fn () => $instance->__invoke())
            ->toThrow(RuntimeException::class, class_basename($seeder).' bloqueado em produção:');
    } finally {
        // The base test case clears the tenant context during teardown.
        $this->app->instance(TenantContext::class, $context);
    }
})->with([DatabaseSeeder::class, TestDataSeeder::class]);
