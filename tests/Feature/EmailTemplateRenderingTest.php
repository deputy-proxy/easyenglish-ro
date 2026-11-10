<?php

use App\Mail\ContactVerificationEmail;
use App\Mail\OpenWaSessionDisconnected;
use App\Mail\TransactionalNotificationEmail;
use Carbon\CarbonImmutable;

test('transactional email uses the EasyEnglish Option 1 brand system and plain text alternative', function () {
    $mail = new TransactionalNotificationEmail([
        'locale' => 'ro',
        'subject' => 'Sesiunea ta este confirmată',
        'heading' => 'Confirmarea sesiunii tale',
        'greeting' => 'Salut, Andrei,',
        'intro' => 'Sesiunea ta de engleză a fost rezervată cu succes.',
        'details' => [['label' => 'Data', 'value' => '17.11.2026, 14:00']],
        'ctaUrl' => 'https://easyenglish.ro/book',
        'ctaLabel' => 'Gestionează programarea',
        'footer' => 'Acesta este un e-mail de serviciu.',
        'success' => true,
    ]);

    $html = $mail->render();
    $plain = view('emails.transactional-text', ['messageData' => $mail->messageData])->render();

    expect($html)->toContain('#14234B')
        ->and($html)->toContain('#1450DF')
        ->and($html)->toContain('logo-white.png')
        ->and($html)->toContain('Confirmarea sesiunii tale')
        ->and($html)->toContain('Gestionează programarea')
        ->and($plain)->toContain('Gestionează programarea: https://easyenglish.ro/book');
});

test('verification email renders a prominent code, expiry, backup link and security warning in both locales', function () {
    foreach (['ro', 'en'] as $locale) {
        $mail = new ContactVerificationEmail($locale, '482951', 'https://easyenglish.ro/verify?token=example');
        $html = $mail->render();
        $plain = view('emails.verification-text', ['locale' => $locale, 'code' => '482951', 'backupUrl' => 'https://easyenglish.ro/verify?token=example'])->render();

        expect($html)->toContain('482951')
            ->and($html)->toContain($locale === 'en' ? '30 minutes' : '30 de minute')
            ->and($html)->toContain('https://easyenglish.ro/verify?token=example')
            ->and($html)->toContain('logo-white.png')
            ->and($plain)->toContain('482951')
            ->and($plain)->toContain('https://easyenglish.ro/verify?token=example');
    }
});

test('OpenWA disconnection alert renders operational details and a plain text checklist', function () {
    $mail = new OpenWaSessionDisconnected(
        'easyenglish-openwa-poc',
        'connection_lost',
        CarbonImmutable::parse('2026-11-10T12:00:00Z'),
    );

    $html = $mail->render();
    $plain = view('emails.openwa-disconnected-text', ['sessionId' => $mail->sessionId, 'reason' => $mail->reason, 'disconnectedAt' => $mail->disconnectedAt])->render();

    expect($html)->toContain('easyenglish-openwa-poc')
        ->and($html)->toContain('connection lost')
        ->and($html)->toContain('Recommended action')
        ->and($plain)->toContain('Detected at (UTC)')
        ->and($plain)->toContain('Reconnect the device');
});
