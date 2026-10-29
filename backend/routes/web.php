<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// A SPA Vue é servida separadamente; o backend expõe API, autenticação e saúde.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'api' => url('/api')]));

Route::get('/ready', function (): JsonResponse {
    try {
        DB::select('SELECT 1');
        Cache::get('health:readiness');
    } catch (Throwable) {
        return new JsonResponse(['status' => 'unavailable'], 503, ['Cache-Control' => 'no-store']);
    }

    return new JsonResponse(['status' => 'ready'], 200, ['Cache-Control' => 'no-store']);
})->withoutMiddleware('web')->name('readiness');

// Rota nomeada exigida pelo broker de redefinição de senha: aponta para a SPA.
Route::get('/reset-password/{token}', function (string $token) {
    $query = http_build_query(['email' => request()->query('email')], '', '&', PHP_QUERY_RFC3986);
    $encodedToken = rawurlencode($token);

    return redirect()->away(rtrim((string) config('homologa.frontend_url'), '/')."/redefinir-senha/{$encodedToken}?{$query}");
})->name('password.reset');
