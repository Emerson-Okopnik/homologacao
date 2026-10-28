<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use App\Jobs\RequestPasswordReset;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;

function runPasswordRecoveryJob(string $email): void
{
    app()->call([new RequestPasswordReset($email), 'handle']);
}

function passwordRecoveryPayload(User $user, string $token): array
{
    return [
        'email' => $user->email,
        'token' => $token,
        'password' => 'NovaSenha12345!',
        'password_confirmation' => 'NovaSenha12345!',
    ];
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->user = app(TenantContext::class)->run($this->tenant, fn () => User::factory()->forTenant($this->tenant)->create([
        'email' => 'recuperar@example.com',
        'password' => 'SenhaAnterior123!',
    ]));
    config(['mail.queue' => 'mail', 'homologa.frontend_url' => 'https://app.empresa.com.br']);
});

it('aceita a mesma solicitação para contas existentes, desconhecidas e inativas sem gerar token na requisição', function (): void {
    Queue::fake();
    $inactive = app(TenantContext::class)->run($this->tenant, fn () => User::factory()->forTenant($this->tenant)->inactive()->create());
    $inactiveTenant = Tenant::factory()->create(['active' => false]);
    $blocked = app(TenantContext::class)->run($inactiveTenant, fn () => User::factory()->forTenant($inactiveTenant)->create());
    $responses = [];

    foreach (['  RECUPERAR@example.com  ', 'desconhecido@example.com', $inactive->email, $blocked->email] as $email) {
        $responses[] = $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertAccepted()->json();
    }

    expect($responses[0])->toBe($responses[1])->toBe($responses[2])->toBe($responses[3]);
    Queue::assertPushed(RequestPasswordReset::class, 4);
    Queue::assertPushedOn('mail', RequestPasswordReset::class, fn (RequestPasswordReset $job) => $job->email === 'recuperar@example.com'
        && $job instanceof ShouldQueue && $job instanceof ShouldBeEncrypted);
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

it('rejeita um e-mail inválido antes de publicar trabalho na fila', function (): void {
    Queue::fake();

    $this->postJson('/api/auth/forgot-password', ['email' => 'endereco-invalido'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    Queue::assertNothingPushed();
});

it('gera token com hash no worker e prepara um e-mail com link do frontend', function (): void {
    Notification::fake();

    runPasswordRecoveryJob($this->user->email);

    Notification::assertSentTo($this->user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification, array $channels): bool {
        $stored = DB::table('password_reset_tokens')->where('email', $this->user->email)->value('token');
        expect($stored)->not->toBe($notification->token)
            ->and(Hash::check($notification->token, $stored))->toBeTrue()
            ->and($notification)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeEncrypted::class)
            ->and($notification->queue)->toBe('mail')
            ->and($notification->toMail($this->user)->actionUrl)
            ->toBe('https://app.empresa.com.br/redefinir-senha/'.$notification->token.'?email=recuperar%40example.com');

        return $channels === ['mail'];
    });
});

it('não gera token nem notificação para conta indisponível', function (string $kind): void {
    Notification::fake();
    $email = $this->user->email;
    if ($kind === 'unknown') {
        $email = 'desconhecido@example.com';
    } elseif ($kind === 'inactive_user') {
        $this->user->forceFill(['active' => false])->saveQuietly();
    } else {
        $this->tenant->forceFill(['active' => false])->saveQuietly();
    }

    runPasswordRecoveryJob($email);

    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
})->with(['unknown', 'inactive_user', 'inactive_tenant']);

it('restaura a notificação criptografada fora do contexto da empresa e entrega HTML ao transporte em memória', function (): void {
    config(['mail.default' => 'array']);
    $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    $transport->flush();
    $connection = app('queue')->connection('sync');
    Queue::fake([SendQueuedNotifications::class]);
    $token = Password::broker()->createToken($this->user);

    $this->user->sendPasswordResetNotification($token);

    Queue::assertPushedOn('mail', SendQueuedNotifications::class);
    $job = Queue::pushed(SendQueuedNotifications::class)->sole();
    $payload = (new ReflectionMethod($connection, 'createPayload'))->invoke($connection, $job, 'mail');
    expect($job->shouldBeEncrypted)->toBeTrue()
        ->and($payload)->not->toContain($token, $this->user->email);
    app(TenantContext::class)->clear();
    $restored = unserialize(app('encrypter')->decrypt(json_decode($payload, true)['data']['command']));
    expect($restored)->toBeInstanceOf(SendQueuedNotifications::class)
        ->and($restored->notifiables->sole()->is($this->user))->toBeTrue()
        ->and(app(TenantContext::class)->has())->toBeFalse();

    $restored->handle(app(ChannelManager::class));

    $message = $transport->messages()->sole()->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe($this->user->email)
        ->and($message->getHtmlBody())->toContain(
            'https://app.empresa.com.br/redefinir-senha/'.$token.'?email=recuperar%40example.com',
            'Redefinir minha senha',
            '60 minutos',
        );
});

it('limita reenvios por e-mail e invalida o link anterior quando um novo é gerado', function (): void {
    Notification::fake();
    runPasswordRecoveryJob($this->user->email);
    $first = Notification::sent($this->user, ResetPasswordNotification::class)->first();

    runPasswordRecoveryJob($this->user->email);
    Notification::assertSentToTimes($this->user, ResetPasswordNotification::class, 1);
    expect($first->shouldSend($this->user, 'mail'))->toBeTrue();

    $this->travel(61)->seconds();
    runPasswordRecoveryJob($this->user->email);
    $second = Notification::sent($this->user, ResetPasswordNotification::class)->last();

    Notification::assertSentToTimes($this->user, ResetPasswordNotification::class, 2);
    expect($first->shouldSend($this->user, 'mail'))->toBeFalse()
        ->and($second->shouldSend($this->user, 'mail'))->toBeTrue();
});

it('descarta notificação expirada ou destinada a uma conta desativada enquanto aguardava a fila', function (string $kind): void {
    $token = Password::broker()->createToken($this->user);
    $notification = new ResetPasswordNotification($token);
    expect($notification->shouldSend($this->user, 'mail'))->toBeTrue();

    if ($kind === 'expired') {
        $this->travel(61)->minutes();
    } elseif ($kind === 'inactive_user') {
        $this->user->forceFill(['active' => false])->saveQuietly();
    } else {
        $this->tenant->forceFill(['active' => false])->saveQuietly();
    }

    expect($notification->shouldSend($this->user, 'mail'))->toBeFalse();
})->with(['expired', 'inactive_user', 'inactive_tenant']);

it('redefine a senha uma única vez, renova o token de sessão e audita somente a empresa do titular', function (): void {
    $otherTenant = Tenant::factory()->create();
    $other = app(TenantContext::class)->run($otherTenant, fn () => User::factory()->forTenant($otherTenant)->create());
    $otherHash = $other->password;
    $remember = $this->user->remember_token;
    $token = Password::broker()->createToken($this->user);
    $notification = new ResetPasswordNotification($token);

    $this->postJson('/api/auth/reset-password', passwordRecoveryPayload($this->user, $token))->assertOk();

    $updated = User::withoutGlobalScope(TenantScope::class)->findOrFail($this->user->id);
    expect(Hash::check('NovaSenha12345!', $updated->password))->toBeTrue()
        ->and($updated->remember_token)->not->toBe($remember)
        ->and(User::withoutGlobalScope(TenantScope::class)->findOrFail($other->id)->password)->toBe($otherHash)
        ->and($notification->shouldSend($updated, 'mail'))->toBeFalse();
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);
    $audit = AuditLog::withoutGlobalScope(TenantScope::class)->where('event', 'auth.password_reset')->sole();
    expect($audit->tenant_id)->toBe($this->tenant->id)
        ->and($audit->user_id)->toBe($this->user->id)
        ->and(json_encode($audit->toArray()))->not->toContain('NovaSenha12345!', $token);

    $this->postJson('/api/auth/reset-password', passwordRecoveryPayload($this->user, $token))
        ->assertUnprocessable()->assertJsonValidationErrors('token');
    expect(AuditLog::withoutGlobalScope(TenantScope::class)->where('event', 'auth.password_reset')->count())->toBe(1);
});

it('rejeita link inválido, expirado e de conta indisponível com o mesmo erro no token', function (string $kind): void {
    $hash = $this->user->password;
    $token = Password::broker()->createToken($this->user);
    $payload = passwordRecoveryPayload($this->user, $token);
    if ($kind === 'invalid') {
        $payload['token'] = 'token-invalido';
    } elseif ($kind === 'expired') {
        $this->travel(61)->minutes();
    } elseif ($kind === 'unknown') {
        $payload['email'] = 'desconhecido@example.com';
    } elseif ($kind === 'inactive_user') {
        $this->user->forceFill(['active' => false])->saveQuietly();
    } else {
        $this->tenant->forceFill(['active' => false])->saveQuietly();
    }

    $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable()
        ->assertJsonPath('errors.token.0', 'Link de redefinição inválido ou expirado. Solicite um novo link.');

    expect(User::withoutGlobalScope(TenantScope::class)->findOrFail($this->user->id)->password)->toBe($hash)
        ->and(AuditLog::withoutGlobalScope(TenantScope::class)->where('event', 'auth.password_reset')->exists())->toBeFalse();
})->with(['invalid', 'expired', 'unknown', 'inactive_user', 'inactive_tenant']);

it('preserva o link quando a nova senha não satisfaz a política de segurança', function (): void {
    $token = Password::broker()->createToken($this->user);
    $payload = [...passwordRecoveryPayload($this->user, $token), 'password' => 'curta', 'password_confirmation' => 'diferente'];

    $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors('password');

    expect(Password::broker()->tokenExists($this->user, $token))->toBeTrue();
});

it('permite nova tentativa quando a publicação da notificação falha depois de criar o token', function (): void {
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Fila indisponível.'));

    expect(fn () => runPasswordRecoveryJob($this->user->email))->toThrow(RuntimeException::class);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);

    Notification::fake();
    runPasswordRecoveryJob($this->user->email);
    Notification::assertSentToOnce($this->user, ResetPasswordNotification::class);
});

it('limita solicitações por origem antes de sobrecarregar a fila', function (): void {
    Queue::fake();
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/auth/forgot-password', ['email' => 'desconhecido@example.com'])->assertAccepted();
    }

    $this->postJson('/api/auth/forgot-password', ['email' => 'desconhecido@example.com'])->assertTooManyRequests();
    Queue::assertPushed(RequestPasswordReset::class, 5);
});
