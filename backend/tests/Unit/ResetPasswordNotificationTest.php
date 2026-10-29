<?php

use App\Domain\Users\Models\User;
use App\Notifications\ResetPasswordNotification;

it('usa o modelo personalizado no e-mail de recuperação de senha', function (): void {
    $user = new User([
        'name' => 'Maria <script>alert(1)</script>',
        'email' => 'maria@example.com',
    ]);
    $notification = new ResetPasswordNotification('token-seguro');

    $message = $notification->toMail($user);
    $html = view('emails.auth.reset-password', $message->viewData)->render();

    expect($message->subject)->toBe('Redefina sua senha | Homologa Solar')
        ->and($message->view)->toBe([
            'html' => 'emails.auth.reset-password',
            'text' => 'emails.auth.reset-password-text',
        ])
        ->and($html)->toContain('Criar nova senha')
        ->and($html)->toContain('/redefinir-senha/token-seguro?email=maria%40example.com')
        ->and($html)->toContain('maria%40example.com')
        ->and($html)->toContain('60 minutos')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

it('envia a notificação personalizada pelo modelo de usuário', function (): void {
    $user = new class(['name' => 'Maria', 'email' => 'maria@example.com']) extends User
    {
        public ?ResetPasswordNotification $sentNotification = null;

        public function notify($instance): void
        {
            $this->sentNotification = $instance;
        }
    };
    $user->sendPasswordResetNotification('token-seguro');

    expect($user->sentNotification)->toBeInstanceOf(ResetPasswordNotification::class)
        ->and($user->sentNotification?->token)->toBe('token-seguro');
});
