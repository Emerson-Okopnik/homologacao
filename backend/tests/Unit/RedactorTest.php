<?php

use App\Domain\Audit\Redactor;

it('mascara chaves sensíveis em qualquer nível, sem diferenciar maiúsculas', function (): void {
    $redactor = new Redactor(['password', 'token', 'secret']);

    expect($redactor->redact([
        'Password' => 'x',
        'access_token' => 'y',
        'config' => ['client_secret' => 'z', 'host' => 'celesc'],
        'name' => 'Ana',
    ]))->toBe([
        'Password' => Redactor::MASK,
        'access_token' => Redactor::MASK,
        'config' => ['client_secret' => Redactor::MASK, 'host' => 'celesc'],
        'name' => 'Ana',
    ]);
});

it('retorna null para entrada nula', function (): void {
    expect((new Redactor(['password']))->redact(null))->toBeNull();
});
