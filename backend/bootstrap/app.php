<?php

use App\Http\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'super_admin' => EnsureSuperAdmin::class,
        ]);
        // O tenant precisa estar resolvido antes do route model binding; caso contrário o
        // TenantScope (fail-closed) faria todo binding retornar 404.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request),
        );
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token']);
    })
    ->create();
