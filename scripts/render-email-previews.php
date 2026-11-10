<?php

use App\Mail\ContactVerificationEmail;
use App\Mail\OpenWaSessionDisconnected;
use App\Mail\TransactionalNotificationEmail;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\View;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.url' => 'https://easyenglish.ro']);

$directory = dirname(__DIR__).'/docs/email-previews';
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}

$renderTransactional = static function (string $filename, array $messageData) use ($directory): void {
    $mail = new TransactionalNotificationEmail($messageData);
    file_put_contents($directory.'/'.$filename.'.html', $mail->render());
    file_put_contents($directory.'/'.$filename.'.txt', View::make('emails.transactional-text', ['messageData' => $messageData])->render());
};

$renderTransactional('01-customer-confirmation', [
    'locale' => 'ro',
    'subject' => 'Confirmarea sesiunii tale',
    'heading' => 'Confirmarea sesiunii tale',
    'greeting' => 'Salut, Andrei,',
    'intro' => 'Sesiunea ta de engleză a fost rezervată cu succes. Ne vedem curând!',
    'details' => [
        ['label' => 'Data', 'value' => '17.11.2026'],
        ['label' => 'Ora', 'value' => '14:00 – 14:30'],
        ['label' => 'Profesor', 'value' => 'Maria Ionescu'],
        ['label' => 'Platformă', 'value' => 'WhatsApp'],
    ],
    'ctaUrl' => 'https://easyenglish.ro/dashboard',
    'ctaLabel' => 'Gestionează programarea',
    'footer' => 'Acesta este un e-mail de serviciu despre programarea ta.',
    'success' => true,
]);

$renderTransactional('02-customer-reminder', [
    'locale' => 'en',
    'subject' => 'Reminder: your Easy English session is tomorrow',
    'heading' => 'Your session is tomorrow',
    'greeting' => 'Hello, Alex,',
    'intro' => 'This is a reminder about your upcoming English session.',
    'details' => [
        ['label' => 'Date', 'value' => '17 November 2026'],
        ['label' => 'Time', 'value' => '14:00 – 14:30'],
        ['label' => 'Teacher', 'value' => 'Maria Ionescu'],
    ],
    'ctaUrl' => null,
    'ctaLabel' => null,
    'footer' => 'This is a service email about your appointment.',
    'success' => false,
]);

$renderTransactional('03-customer-cancellation', [
    'locale' => 'ro',
    'subject' => 'Programarea ta a fost anulată',
    'heading' => 'Programarea a fost anulată',
    'greeting' => 'Salut, Andrei,',
    'intro' => 'Programarea ta a fost anulată. Detaliile de mai jos explică actualizarea.',
    'details' => [
        ['label' => 'Data inițială', 'value' => '17.11.2026, 14:00'],
        ['label' => 'Situația creditului', 'value' => 'Creditul a fost restituit în pachet.'],
    ],
    'ctaUrl' => 'https://easyenglish.ro/dashboard',
    'ctaLabel' => 'Vezi programările',
    'footer' => 'Acesta este un e-mail de serviciu despre programarea ta.',
    'success' => false,
]);

$renderTransactional('04-teacher-assignment', [
    'locale' => 'en',
    'subject' => 'New Easy English session assigned to you',
    'heading' => 'A new session is assigned to you',
    'greeting' => 'Hello, Maria,',
    'intro' => 'A booking has been confirmed and assigned to you. Review the learner details and prepare for the session.',
    'details' => [
        ['label' => 'Customer', 'value' => 'Alex Popescu'],
        ['label' => 'Customer email', 'value' => 'alex@example.com'],
        ['label' => 'English level', 'value' => 'B1'],
        ['label' => 'Session goal', 'value' => 'Work conversations'],
        ['label' => 'Appointment', 'value' => '17.11.2026, 14:00'],
    ],
    'ctaUrl' => 'https://easyenglish.ro/teacher/bookings/demo',
    'ctaLabel' => 'Open booking',
    'footer' => 'This is a service email about an assigned session.',
    'success' => false,
]);

$verification = new ContactVerificationEmail('ro', '482951', 'https://easyenglish.ro/verify?token=preview-token');
file_put_contents($directory.'/05-email-verification.html', $verification->render());
file_put_contents($directory.'/05-email-verification.txt', View::make('emails.verification-text', [
    'locale' => 'ro',
    'code' => '482951',
    'backupUrl' => 'https://easyenglish.ro/verify?token=preview-token',
])->render());

$openWa = new OpenWaSessionDisconnected(
    'easyenglish-openwa-poc',
    'connection_lost',
    CarbonImmutable::parse('2026-11-10T12:00:00Z'),
);
file_put_contents($directory.'/06-openwa-alert.html', $openWa->render());
file_put_contents($directory.'/06-openwa-alert.txt', View::make('emails.openwa-disconnected-text', [
    'sessionId' => $openWa->sessionId,
    'reason' => $openWa->reason,
    'disconnectedAt' => $openWa->disconnectedAt,
])->render());

fwrite(STDOUT, 'Rendered six HTML and plain-text email previews to docs/email-previews.'.PHP_EOL);
