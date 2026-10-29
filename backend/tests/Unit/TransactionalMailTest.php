<?php

use App\Domain\Users\Models\User;
use App\Jobs\RequestPasswordReset;
use App\Notifications\MailTransportTestNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

it('protege o destinatário e o token no payload real de fila, inclusive para failed_jobs', function (): void {
    $queue = new class extends SyncQueue
    {
        public function payloadFor(object $job): string
        {
            return $this->createPayload($job, 'mail');
        }
    };
    $queue->setContainer(app());

    $token = 'token-secreto-que-nao-pode-aparecer-no-redis';
    $user = new User(['name' => 'Ana', 'email' => 'ana@example.test']);
    $jobs = [
        [new RequestPasswordReset($user->email), $user->email],
        [new SendQueuedNotifications($user, new ResetPasswordNotification($token), ['mail']), $token],
    ];

    foreach ($jobs as [$job, $secret]) {
        $payload = $queue->payloadFor($job);
        $command = json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'];

        expect($payload)->not->toContain($secret)
            ->and(Crypt::decrypt($command))->toContain($secret);
    }
});

it('renderiza o e-mail de recuperação com o link do frontend e orientações em português', function (): void {
    config(['homologa.frontend_url' => 'https://app.empresa.com.br', 'app.name' => 'VERIGRID']);
    $user = new User(['name' => 'Ana <script>alert(1)</script>', 'email' => 'ana+solar@example.com']);
    $message = (new ResetPasswordNotification('token-teste'))->toMail($user);
    $html = (string) $message->render();

    expect($html)->toContain(
        'https://app.empresa.com.br/redefinir-senha/token-teste?email=ana%2Bsolar%40example.com',
        'Criar nova senha',
        'uma única vez',
        '60 minutos',
        'a partir da geração do link',
        'use o mais recente',
        'Todos os direitos reservados.',
    )->not->toContain('<script>', 'localhost', 'Hello!', 'Laravel');
});

it('inspeciona configuração sem conectar ao provedor nem enfileirar e-mails', function (): void {
    Notification::fake();
    config(['mail.default' => 'resend', 'services.resend.key' => 're_test_key']);

    $this->artisan('mail:test', ['recipient' => 'destino@example.test'])
        ->expectsOutput('Configuração carregada. Nenhuma conexão externa ou envio foi realizado. Use --send para testar a entrega.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('enfileira o teste somente com opção de envio explícita e endereço válido', function (): void {
    Notification::fake();

    $this->artisan('mail:test', ['recipient' => 'invalido', '--send' => true])->assertFailed();
    Notification::assertNothingSent();

    $this->artisan('mail:test', ['recipient' => 'destino@example.test', '--send' => true])->assertSuccessful();
    Notification::assertSentOnDemand(MailTransportTestNotification::class, function ($notification, $channels, $notifiable): bool {
        return $notifiable->routes['mail'] === 'destino@example.test' && $notification->queue === 'mail';
    });
});

it('constrói SMTP com limite de tempo e TLS obrigatório sem desativar verificação de certificado', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.scheme' => 'smtps',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 465,
        'mail.mailers.smtp.require_tls' => true,
    ]);

    $transport = Mail::mailer('smtp')->getSymfonyTransport();
    $stream = $transport->getStream();

    expect($stream->isTLS())->toBeTrue()
        ->and($stream->getTimeout())->toBe(15.0)
        ->and($stream->getStreamOptions()['ssl']['verify_peer'] ?? true)->toBeTrue()
        ->and($stream->getStreamOptions()['ssl']['verify_peer_name'] ?? true)->toBeTrue();
});
