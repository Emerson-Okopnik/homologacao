<?php

use App\Domain\Users\Models\User;

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    | O provider "tenant-eloquent" carrega o usuário autenticado ignorando o TenantScope
    | (ainda não existe tenant resolvido nesse momento). Depois da autenticação, o
    | middleware ResolveTenant fixa o tenant do próprio usuário para o restante da request.
    */
    'providers' => [
        'users' => [
            'driver' => 'tenant-eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
