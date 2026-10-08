<?php

return [

    /*
    | Driver do SecretProvider: env (Wave 1). Futuro: vault, aws_secrets_manager.
    */
    'driver' => env('SECRETS_DRIVER', 'env'),

    /*
    | Allowlist de referências resolvíveis pelo EnvSecretProvider.
    | O banco guarda apenas a referência (ex.: secret_ref = CELESC_CREDENTIAL_PRODUCTION),
    | nunca o valor. Referências fora desta lista são recusadas, o que impede que uma
    | referência gravada no banco leia variáveis arbitrárias (ex.: APP_KEY, DB_PASSWORD).
    */
    'refs' => [
        'CELESC_CREDENTIAL_PRODUCTION' => env('CELESC_CREDENTIAL_PRODUCTION'),
        'CELESC_CREDENTIAL_SANDBOX' => env('CELESC_CREDENTIAL_SANDBOX'),
    ],
];
