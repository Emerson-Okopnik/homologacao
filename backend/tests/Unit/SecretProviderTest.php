<?php

use App\Infrastructure\Secrets\EnvSecretProvider;
use App\Infrastructure\Secrets\SecretNotFoundException;
use App\Infrastructure\Secrets\SecretProvider;

it('resolve o SecretProvider para o driver env', function (): void {
    expect(app(SecretProvider::class))->toBeInstanceOf(EnvSecretProvider::class);
});

it('lança exceção para referência inexistente sem expor valores', function (): void {
    app(SecretProvider::class)->get('REFERENCIA_QUE_NAO_EXISTE_'.uniqid());
})->throws(SecretNotFoundException::class);
