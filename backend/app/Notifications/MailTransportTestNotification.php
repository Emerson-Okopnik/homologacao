<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MailTransportTestNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue(config('mail.queue', 'mail'));
        $this->locale('pt_BR');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Teste de e-mail · '.config('app.name'))
            ->greeting('Olá!')
            ->line('Este é um teste do envio de e-mails de '.config('app.name').'.')
            ->line('Se você recebeu esta mensagem, a fila e o Resend processaram o teste.')
            ->line('Nenhuma senha foi alterada.');
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('mail.delivery_failed', [
            'notification' => self::class,
            'notification_id' => $this->id,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
