<?php

return [

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:8080'),

    'auth' => [
        'max_login_attempts' => (int) env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_seconds' => (int) env('AUTH_LOCKOUT_SECONDS', 60),
    ],

    'audit' => [
        // Chaves cujo valor é sempre substituído por "[REDACTED]" antes de ir para o audit log.
        'redacted_keys' => [
            'password', 'password_confirmation', 'current_password', 'remember_token',
            'token', 'access_token', 'refresh_token', 'api_key', 'apikey', 'secret',
            'client_secret', 'authorization', 'cookie', 'credential', 'credentials',
            'private_key', 'two_factor_secret', 'two_factor_recovery_codes',
        ],
    ],

    /*
    | Metas técnicas de engenharia. NÃO são SLA contratual nem exigência da CELESC.
    | Status: a validar pelo negócio.
    */
    'engineering_targets' => [
        'status' => 'a_validar_pelo_negocio',
        'availability_percent' => (float) env('TARGET_AVAILABILITY_PERCENT', 99.5),
        'p95_common_screens_ms' => (int) env('TARGET_P95_MS', 2000),
        'rpo_hours' => (int) env('TARGET_RPO_HOURS', 24),
        'rto_hours' => (int) env('TARGET_RTO_HOURS', 8),
    ],
];
