<?php

use App\Models\Booking;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-25 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function createRetentionBooking(array $overrides = []): Booking
{
    return Booking::query()->create(array_merge([
        'public_reference' => (string) str()->uuid(),
        'customer_name' => 'Retention Test',
        'customer_email' => 'retention@example.com',
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practise interviews',
        'notes' => 'Discuss a job change',
        'accepted_policy_version' => '2026-10-25',
        'starts_at' => now()->subMonths(12)->subSecond(),
        'ends_at' => now()->subMonths(12)->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'cancelled',
        'cancelled_at' => now()->subMonths(12),
    ], $overrides));
}

test('retention command is dry-run by default and does not change booking content', function () {
    $booking = createRetentionBooking();

    $this->artisan('bookings:anonymize-old-session-notes')
        ->expectsOutputToContain('Dry run: 1 booking record(s)')
        ->assertSuccessful();

    expect($booking->fresh()->session_goal)->toBe('Practise interviews')
        ->and($booking->fresh()->notes)->toBe('Discuss a job change');
});

test('retention write mode is blocked until explicitly enabled', function () {
    createRetentionBooking();
    config(['easyenglish.retention_enabled' => false]);

    $this->artisan('bookings:anonymize-old-session-notes --apply')
        ->expectsOutputToContain('Retention writes are disabled')
        ->assertFailed();

    expect(Booking::query()->firstOrFail()->session_goal)->toBe('Practise interviews');
});

test('approved retention operation clears only old session goals and notes and is repeatable', function () {
    $old = createRetentionBooking();
    $boundary = createRetentionBooking([
        'public_reference' => (string) str()->uuid(),
        'customer_email' => 'boundary@example.com',
        'customer_phone' => '+40722123457',
        'starts_at' => now()->subMonths(12)->addSecond(),
        'ends_at' => now()->subMonths(12)->addMinutes(15)->addSecond(),
    ]);
    config(['easyenglish.retention_enabled' => true]);

    $this->artisan('bookings:anonymize-old-session-notes --apply')->assertSuccessful();

    expect($old->fresh()->session_goal)->toBe('[anonymized]')
        ->and($old->fresh()->notes)->toBeNull()
        ->and($old->fresh()->customer_email)->toBe('retention@example.com')
        ->and($old->fresh()->public_reference)->toBe($old->public_reference)
        ->and($boundary->fresh()->session_goal)->toBe('Practise interviews');

    $this->artisan('bookings:anonymize-old-session-notes --apply')->assertSuccessful();
    expect($old->fresh()->session_goal)->toBe('[anonymized]');
});
