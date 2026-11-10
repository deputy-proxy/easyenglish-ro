<?php

use App\Enums\NotificationEvent;

test('every transactional notification event has Romanian and English template copy', function () {
    $romanian = require lang_path('ro/email.php');
    $english = require lang_path('en/email.php');

    foreach (NotificationEvent::cases() as $event) {
        foreach ([$romanian, $english] as $translations) {
            $copy = $translations['events'][$event->value] ?? null;

            expect($copy)->toBeArray()
                ->and($copy['subject'] ?? null)->toBeString()->not->toBeEmpty()
                ->and($copy['heading'] ?? null)->toBeString()->not->toBeEmpty()
                ->and($copy['intro'] ?? null)->toBeString()->not->toBeEmpty();
        }
    }
});

test('transactional layout omits the success marker and action button when not applicable', function () {
    $html = view('emails.transactional', ['messageData' => [
        'locale' => 'en',
        'subject' => 'Reminder',
        'heading' => 'Your session is tomorrow',
        'greeting' => 'Hello, learner,',
        'intro' => 'A reminder about your session.',
        'details' => [],
        'ctaUrl' => null,
        'ctaLabel' => null,
        'footer' => 'Service email.',
        'success' => false,
    ]])->render();

    expect($html)->not->toContain('&#10003;')
        ->and($html)->not->toContain('href=""')
        ->and($html)->toContain('Your session is tomorrow');
});
