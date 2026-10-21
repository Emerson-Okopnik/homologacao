<?php

$allowedOrigins = [rtrim((string) env('FRONTEND_URL', 'http://localhost:8080'), '/')];

if (in_array(env('APP_ENV'), ['local', 'testing'], true)) {
    $allowedOrigins = array_merge($allowedOrigins, [
        'http://localhost:8080',
        'http://localhost:5173',
        'http://127.0.0.1:8080',
        'http://127.0.0.1:5173',
    ]);
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // Cookie authentication requires explicit origins, including when misconfigured.
    'allowed_origins' => array_values(array_unique(array_filter(
        $allowedOrigins,
        fn (string $origin): bool => $origin !== '' && ! str_contains($origin, '*'),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Correlation-Id'],
    'max_age' => 600,
    'supports_credentials' => true,
];
