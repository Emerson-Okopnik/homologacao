<?php

namespace App\Notifications\Auth;

use App\Domain\Users\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

final class ResetPasswordNotification extends ResetPassword
{
    /** @param User $notifiable */
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
            ->subject('Redefina sua senha | Homologa Solar')
            ->view('emails.auth.reset-password', $data)
            ->text('emails.auth.reset-password-text', $data);
    }
}
