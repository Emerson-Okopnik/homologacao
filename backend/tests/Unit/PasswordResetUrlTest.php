<?php

use App\Domain\Users\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;

it('envia o link de recuperacao para a rota de redefinicao do Vue', function (string $frontendUrl): void {
    config(['homologa.frontend_url' => $frontendUrl]);
    $user = new User(['email' => 'ana+solar@empresa.com.br']);
    $notification = new ResetPassword('token-seguro-123');

    expect($notification->toMail($user)->actionUrl)
        ->toBe('https://app.empresa.com.br/redefinir-senha/token-seguro-123?email=ana%2Bsolar%40empresa.com.br');
})->with(['https://app.empresa.com.br', 'https://app.empresa.com.br/']);

it('codifica o token como um unico parametro de rota', function (): void {
    config(['homologa.frontend_url' => 'https://app.empresa.com.br']);
    $user = new User(['email' => 'ana@empresa.com.br']);
    $notification = new ResetPassword('token/com?caracteres#especiais');

    expect($notification->toMail($user)->actionUrl)
        ->toBe('https://app.empresa.com.br/redefinir-senha/token%2Fcom%3Fcaracteres%23especiais?email=ana%40empresa.com.br');
});

it('redireciona a rota nomeada de recuperacao para a mesma rota do Vue', function (): void {
    config(['homologa.frontend_url' => 'https://app.empresa.com.br/']);

    $this->get('/reset-password/token-seguro-123?email=ana%2Bsolar%40empresa.com.br')
        ->assertRedirect('https://app.empresa.com.br/redefinir-senha/token-seguro-123?email=ana%2Bsolar%40empresa.com.br');
});
