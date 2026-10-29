<?php

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;

it('configura o transporte de e-mail do Resend', function (): void {
    config()->set('services.resend.key', 're_test_key');

    expect(config('mail.mailers.resend.transport'))->toBe('resend')
        ->and(Mail::mailer('resend')->getSymfonyTransport())->toBeInstanceOf(ResendTransport::class);
});
