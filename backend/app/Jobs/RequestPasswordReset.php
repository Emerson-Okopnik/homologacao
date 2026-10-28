<?php

namespace App\Jobs;

use App\Domain\Users\Models\User;
use App\Domain\Users\PasswordResetCredentials;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

final class RequestPasswordReset implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $email)
    {
        $this->onQueue(config('mail.queue', 'mail'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $broker = Password::broker();
        $broker->sendResetLink(PasswordResetCredentials::forEmail($this->email), function (User $user, string $token) use ($broker): void {
            try {
                $user->sendPasswordResetNotification($token);
            } catch (Throwable $exception) {
                // Permite repetir o pedido se a fila não aceitou a notificação.
                $broker->deleteToken($user);

                throw $exception;
            }

            event(new PasswordResetLinkSent($user));
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('mail.password_reset_request_failed', ['exception' => $exception ? $exception::class : null]);
    }
}
