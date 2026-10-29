<?php

use App\Domain\Users\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

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
        ->and($html)->toContain('token=token-seguro')
        ->and($html)->toContain('maria%40example.com')
        ->and($html)->toContain('60 minutos')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

it('envia a notificação personalizada pelo modelo de usuário', function (): void {
    Notification::fake();

    $user = new User(['name' => 'Maria', 'email' => 'maria@example.com']);
    $user->sendPasswordResetNotification('token-seguro');

    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        fn (ResetPasswordNotification $notification): bool => $notification->token === 'token-seguro',
    );
});
