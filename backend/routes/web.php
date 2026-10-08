<?php

use Illuminate\Support\Facades\Route;

// A SPA Vue é servida pelo Nginx/Vite. O backend expõe apenas /api, /sanctum e /up.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'api' => url('/api')]));

// Rota nomeada exigida pelo broker de redefinição de senha: aponta para a SPA.
Route::get('/reset-password/{token}', function (string $token) {
    $query = http_build_query(['token' => $token, 'email' => request()->query('email')]);

    return redirect()->away(rtrim((string) config('homologa.frontend_url'), '/')."/redefinir-senha?{$query}");
})->name('password.reset');
