<?php

namespace App\Notifications;

use App\Domain\Users\Models\User;
use App\Domain\Users\PasswordResetCredentials;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

final class ResetPasswordNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
        $this->onQueue(config('mail.queue', 'mail'));
        $this->afterCommit();
        $this->locale('pt_BR');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        // Jobs repetidos nunca devem enviar um link já usado, substituído ou expirado.
        return $channel === 'mail'
            && PasswordResetCredentials::eligible($notifiable)
            && Password::broker()->tokenExists($notifiable, $this->token);
    }

    public function toMail($notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);
        $expiresInMinutes = (int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
            60,
        );
        $data = [
            'userName' => trim((string) $notifiable->name),
            'userEmail' => (string) $notifiable->getEmailForPasswordReset(),
            'resetUrl' => $resetUrl,
            'expiresInMinutes' => $expiresInMinutes,
            'year' => now()->year,
        ];

        return (new MailMessage)
            ->subject('Redefina sua senha | '.config('app.name'))
            ->action('Criar nova senha', $resetUrl)
            ->view('emails.auth.reset-password', $data)
            ->text('emails.auth.reset-password-text', $data);
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
