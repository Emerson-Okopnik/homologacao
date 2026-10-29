<?php

return [
    'default' => env('MAIL_MAILER', 'resend'),
    'queue' => env('MAIL_QUEUE', 'mail'),

    'mailers' => [
        'resend' => [
            'transport' => 'resend',
        ],

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME', 'smtp'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => (int) env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => (int) env('MAIL_TIMEOUT', 15),
            'require_tls' => (bool) env('MAIL_REQUIRE_TLS', env('APP_ENV') === 'production'),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'nao-responda@verigrid.com.br'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'VERIGRID')),
    ],

    'markdown' => [
        'theme' => env('MAIL_MARKDOWN_THEME', 'default'),
        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],
];
