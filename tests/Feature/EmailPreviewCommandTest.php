<?php

use App\Mail\ContactVerificationEmail;
use App\Mail\TransactionalNotificationEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('email preview command renders all twenty email types in both locales without sending', function () {
    $this->artisan('emails:preview')
        ->expectsOutputToContain('Rendered 40 email variant(s)')
        ->expectsOutputToContain('No email was sent.')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles('email-previews'))->toHaveCount(80)
        ->and(Storage::disk('local')->exists('email-previews/appointment_confirmed-ro.html'))->toBeTrue()
        ->and(Storage::disk('local')->exists('email-previews/appointment_confirmed-en.txt'))->toBeTrue()
        ->and(Storage::disk('local')->exists('email-previews/verification-ro.html'))->toBeTrue()
        ->and(Storage::disk('local')->exists('email-previews/openwa_disconnected-en.txt'))->toBeTrue();
});

test('email preview command can render one selected event and locale', function () {
    $this->artisan('emails:preview', ['--event' => 'appointment_confirmed', '--locale' => 'ro'])
        ->expectsOutputToContain('Rendered 1 email variant(s)')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles('email-previews'))->toHaveCount(2)
        ->and(Storage::disk('local')->exists('email-previews/appointment_confirmed-ro.html'))->toBeTrue()
        ->and(Storage::disk('local')->exists('email-previews/appointment_confirmed-en.html'))->toBeFalse();
});

test('email preview command sends only a specifically selected test email when explicitly requested', function () {
    Mail::fake();

    $this->artisan('emails:preview', [
        '--event' => 'appointment_confirmed',
        '--locale' => 'ro',
        '--send' => true,
        '--to' => 'preview@example.com',
    ])->expectsOutputToContain('Sent one preview email to preview@example.com')
        ->assertSuccessful();

    Mail::assertSent(TransactionalNotificationEmail::class);
    $sent = Mail::sent(TransactionalNotificationEmail::class)->first();

    expect($sent)->not->toBeNull()
        ->and($sent->subject)->toStartWith('[PREVIEW]');
});

test('email preview command refuses broad or unsafe sends', function () {
    $this->artisan('emails:preview', ['--send' => true, '--to' => 'preview@example.com'])
        ->expectsOutputToContain('For safety, sending requires one specific --event and one specific --locale.')
        ->assertExitCode(2);

    $this->artisan('emails:preview', [
        '--event' => 'appointment_confirmed',
        '--locale' => 'ro',
        '--send' => true,
    ])->expectsOutputToContain('A valid --to email address is required')
        ->assertExitCode(2);
});

test('email preview command supports verification test delivery', function () {
    Mail::fake();

    $this->artisan('emails:preview', [
        '--event' => 'verification',
        '--locale' => 'en',
        '--send' => true,
        '--to' => 'preview@example.com',
    ])->assertSuccessful();

    Mail::assertSent(ContactVerificationEmail::class);
});

test('email preview command requires an additional confirmation before production sends', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('emails:preview', [
        '--event' => 'appointment_confirmed',
        '--locale' => 'en',
        '--send' => true,
        '--to' => 'preview@example.com',
    ])->expectsOutputToContain('Sending from production requires the additional --force option.')
        ->assertExitCode(1);
});
