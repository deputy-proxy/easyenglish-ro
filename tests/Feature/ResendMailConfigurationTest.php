<?php

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;

test('resend mailer resolves from the configured API key without sending a message', function (): void {
    config([
        'mail.mailers.resend' => ['transport' => 'resend'],
        'services.resend.key' => 're_test_configuration_only',
    ]);

    expect(Mail::mailer('resend')->getSymfonyTransport())
        ->toBeInstanceOf(ResendTransport::class);
});
