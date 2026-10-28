<?php

use App\Domain\Users\Models\User;
use App\Http\Exceptions\ApiExceptionRenderer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\PresenceVerifierInterface;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

it('retorna em português os erros de e-mail duplicado e senha curta da API', function (): void {
    $presence = Mockery::mock(PresenceVerifierInterface::class);
    $presence->shouldReceive('getCount')->once()->andReturn(1);
    Validator::setPresenceVerifier($presence);

    $validator = Validator::make(
        ['email' => 'existente@example.com', 'password' => 'Senha1'],
        ['email' => 'required|email|unique:users,email', 'password' => ['required', Password::min(10)->letters()->numbers()->mixedCase()]],
    );

    $response = app(ApiExceptionRenderer::class)->render(
        new ValidationException($validator),
        Request::create('/api/users', 'POST'),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))
        ->toMatchArray([
            'message' => 'Os dados informados são inválidos.',
            'code' => 'validation_failed',
            'errors' => [
                'email' => ['Este e-mail já está cadastrado.'],
                'password' => ['O campo senha deve conter pelo menos 10 caracteres.'],
            ],
        ]);
});

it('traduz as regras de complexidade de senha', function (): void {
    $validator = Validator::make(
        ['password' => 'abcdefghij'],
        ['password' => [Password::min(10)->letters()->numbers()->mixedCase()]],
    );

    expect($validator->errors()->get('password'))->toContain(
        'O campo senha deve conter pelo menos uma letra maiúscula e uma minúscula.',
        'O campo senha deve conter pelo menos um número.',
    );
});

it('traduz campos obrigatórios, confirmações, arquivos e campos de listas', function (): void {
    $validator = Validator::make(
        ['password' => 'Senha12345', 'password_confirmation' => 'outra', 'file' => 'texto', 'system' => ['inverters' => [['quantity' => 0]]]],
        ['name' => 'required', 'password' => 'confirmed', 'file' => 'file', 'system.inverters.*.quantity' => 'integer|min:1'],
    );

    expect($validator->errors()->toArray())->toBe([
        'name' => ['O campo nome é obrigatório.'],
        'password' => ['A confirmação do campo senha não confere.'],
        'file' => ['O campo arquivo deve conter um arquivo.'],
        'system.inverters.0.quantity' => ['O campo quantidade de inversores deve ser maior ou igual a 1.'],
    ]);
});

it('traduz a validação na rota de acesso sem mudar as chaves da API', function (): void {
    $this->postJson('/api/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'O campo e-mail é obrigatório.')
        ->assertJsonPath('errors.password.0', 'O campo senha é obrigatório.');
});

it('cobre todas as regras do catálogo do Laravel e preserva seus parâmetros', function (): void {
    $english = Arr::dot(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
    $portuguese = Arr::dot(require lang_path('pt_BR/validation.php'));

    foreach ($english as $key => $message) {
        if (! is_string($message) || str_starts_with($key, 'custom.') || str_starts_with($key, 'attributes.')) {
            continue;
        }

        expect($portuguese)->toHaveKey($key);
        preg_match_all('/:[a-z_]+/', $message, $expected);
        preg_match_all('/:[a-z_]+/', $portuguese[$key], $actual);
        expect(array_unique($actual[0]))->toEqualCanonicalizing(array_unique($expected[0]));
    }
});

it('traduz a notificação de recuperação de senha e o modelo do e-mail', function (): void {
    $message = (new ResetPassword('token-teste'))->toMail(new User(['email' => 'ana@example.com']));
    $html = (string) $message->render();

    expect($message->subject)->toBe('Redefina sua senha')
        ->and($message->actionText)->toBe('Redefinir senha')
        ->and($html)->toContain('Olá!', 'Atenciosamente,', 'Todos os direitos reservados.', 'copie e cole o endereço abaixo')
        ->not->toContain('Hello!', 'Regards,', 'All rights reserved.', 'If you did not request');
});

it('traduz erros HTTP do framework e preserva mensagens específicas em português', function (): void {
    $request = Request::create('/api/teste');
    $renderer = app(ApiExceptionRenderer::class);

    expect($renderer->render(new AccessDeniedHttpException, $request)->getData(true)['message'])
        ->toBe('Você não tem permissão para esta ação.')
        ->and($renderer->render(new AccessDeniedHttpException('Acesso restrito à equipe.'), $request)->getData(true)['message'])
        ->toBe('Acesso restrito à equipe.');
});
