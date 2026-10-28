<?php

use Symfony\Component\HttpFoundation\Cookie;

function corsConfigurationFor(string $environment, string $frontendUrl): array
{
    $variables = ['APP_ENV' => $environment, 'FRONTEND_URL' => $frontendUrl];
    $previous = [
        'APP_ENV' => $_SERVER['APP_ENV'] ?? null,
        'FRONTEND_URL' => $_SERVER['FRONTEND_URL'] ?? null,
    ];

    try {
        foreach ($variables as $name => $value) {
            $_SERVER[$name] = $value;
        }

        return require config_path('cors.php');
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }
    }
}

beforeEach(function (): void {
    config(['cors' => corsConfigurationFor('production', 'https://app.empresa.com.br/')]);
});

it('permite preflight com credenciais para a origem do frontend na API e no CSRF', function (string $path, string $method): void {
    $this->options($path, [], [
        'Origin' => 'https://app.empresa.com.br',
        'Access-Control-Request-Method' => $method,
        'Access-Control-Request-Headers' => 'content-type,x-xsrf-token,x-correlation-id',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.empresa.com.br')
        ->assertHeader('Access-Control-Allow-Credentials', 'true')
        ->assertHeader('Access-Control-Allow-Methods', $method)
        ->assertHeader('Access-Control-Allow-Headers', 'content-type,x-xsrf-token,x-correlation-id');
})->with([
    'login' => ['/api/auth/login', 'POST'],
    'CSRF' => ['/sanctum/csrf-cookie', 'GET'],
]);

it('impede que outra origem use a resposta de preflight da API', function (string $origin): void {
    $response = $this->options('/api/auth/login', [], [
        'Origin' => $origin,
        'Access-Control-Request-Method' => 'POST',
    ])->assertNoContent();

    // With one configured origin, the CORS middleware returns that fixed origin;
    // the browser rejects it when it does not match the requesting origin.
    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe($origin)
        ->not->toBe('*');
})->with([
    'terceiro' => ['https://outro.example'],
    'subdominio parecido' => ['https://app.empresa.com.br.outro.example'],
    'desenvolvimento em producao' => ['http://localhost:5173'],
]);

it('mantem origens locais somente no ambiente de desenvolvimento', function (): void {
    expect(config('cors.allowed_origins'))->toBe(['https://app.empresa.com.br']);

    config(['cors' => corsConfigurationFor('local', 'http://localhost:8080')]);

    $this->options('/api/auth/login', [], [
        'Origin' => 'http://localhost:5173',
        'Access-Control-Request-Method' => 'POST',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('nao aceita curingas na configuracao de origem com credenciais', function (string $origin): void {
    config(['cors' => corsConfigurationFor('production', $origin)]);

    $this->options('/api/auth/login', [], [
        'Origin' => 'https://outro.example',
        'Access-Control-Request-Method' => 'POST',
    ])
        ->assertNoContent()
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
})->with(['*', 'https://*.empresa.com.br']);

it('emite cookies HTTPS compartilhados entre subdominios e expoe a correlacao', function (): void {
    config([
        'session.domain' => '.empresa.com.br',
        'session.secure' => true,
        'session.same_site' => 'lax',
        'sanctum.stateful' => ['app.empresa.com.br'],
    ]);

    $response = $this->getJson('https://api.empresa.com.br/sanctum/csrf-cookie', [
        'Origin' => 'https://app.empresa.com.br',
        'Referer' => 'https://app.empresa.com.br/',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.empresa.com.br')
        ->assertHeader('Access-Control-Allow-Credentials', 'true')
        ->assertHeader('Access-Control-Expose-Headers', 'X-Correlation-Id')
        ->assertHeader('X-Correlation-Id');

    $cookies = collect($response->headers->getCookies())->keyBy(fn (Cookie $cookie): string => $cookie->getName());

    foreach (['XSRF-TOKEN', config('session.cookie')] as $name) {
        expect($cookies->has($name))->toBeTrue();
        expect($cookies[$name]->getDomain())->toBe('.empresa.com.br')
            ->and($cookies[$name]->isSecure())->toBeTrue()
            ->and($cookies[$name]->getSameSite())->toBe('lax');
    }

    expect($cookies['XSRF-TOKEN']->isHttpOnly())->toBeFalse()
        ->and($cookies[config('session.cookie')]->isHttpOnly())->toBeTrue();
});
