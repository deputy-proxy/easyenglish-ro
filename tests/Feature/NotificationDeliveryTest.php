<?php

use App\Enums\NotificationEvent;
use App\Jobs\SendNotificationDelivery;
use App\Mail\TransactionalNotificationEmail;
use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\NotificationDelivery;
use App\Services\Booking\BookingService;
use App\Services\Notifications\NotificationMessageFactory;
use App\Services\Notifications\NotificationOutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;

function makeNotificationTestBooking(string $locale = 'ro', ?int $entitlementId = null, ?CarbonImmutable $startsAt = null): Booking
{
    $startsAt ??= CarbonImmutable::now('UTC')->addDays(3);

    return Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'bundle_entitlement_id' => $entitlementId,
        'customer_name' => 'Notification Test Customer',
        'customer_email' => 'notification-test@example.com',
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practice workplace conversations',
        'notes' => null,
        'accepted_policy_version' => '2026-10-25',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'locale' => $locale,
        'hold_expires_at' => null,
        'confirmed_at' => now(),
    ]);
}

function makeNotificationTestEntitlement(int $expiryDays = 90): BundleEntitlement
{
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();

    return BundleEntitlement::query()->create([
        'bundle_definition_id' => $bundle->id,
        'customer_name' => 'Notification Test Customer',
        'customer_email' => 'notification-test@example.com',
        'customer_phone' => '+40722123456',
        'locale' => 'ro',
        'status' => 'active',
        'paid_amount_ron' => 156,
        'currency' => 'RON',
        'paid_credits' => 4,
        'free_credits_granted' => 0,
        'credits_remaining' => 2,
        'free_credits_remaining' => 0,
        'activated_at' => now(),
        'expires_at' => now()->addDays($expiryDays),
    ]);
}

test('confirmed booking creates one bilingual transactional outbox record and duplicate job execution sends once', function () {
    Mail::fake();
    $booking = makeNotificationTestBooking('en');
    $outbox = app(NotificationOutboxService::class);
    $delivery = $outbox->enqueueBookingConfirmation($booking);
    $duplicate = $outbox->enqueueBookingConfirmation($booking);

    expect($delivery)->not->toBeNull()
        ->and($duplicate?->id)->toBe($delivery?->id)
        ->and(NotificationDelivery::query()->count())->toBe(1);

    $delivery->update(['status' => 'queued']);
    $job = new SendNotificationDelivery($delivery->id);
    $job->handle(app(NotificationMessageFactory::class));
    $job->handle(app(NotificationMessageFactory::class));

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->attempts)->toBe(1);
    Mail::assertSent(TransactionalNotificationEmail::class, 1);
    $sentMail = Mail::sent(TransactionalNotificationEmail::class)->first();
    expect($sentMail->messageData['locale'])->toBe('en')
        ->and($sentMail->messageData['subject'])->toContain('free introductory session is confirmed')
        ->and($sentMail->render())->toContain('WhatsApp');
});

test('booking confirmation commits one outbox event even when confirmation is retried', function () {
    $booking = makeNotificationTestBooking('en');
    $booking->update(['status' => 'pending', 'confirmed_at' => null, 'hold_expires_at' => now()->addMinutes(5)]);
    $service = app(BookingService::class);

    $service->confirm($booking->id);
    $service->confirm($booking->id);

    expect($booking->fresh()->status)->toBe('confirmed')
        ->and(NotificationDelivery::query()->where('booking_id', $booking->id)->where('event', 'intro_booking_confirmed')->count())->toBe(1);
});

test('SMTP failures remain visible and schedule a bounded retry without logging message or recipient data', function () {
    $booking = makeNotificationTestBooking();
    $delivery = app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $delivery->update(['status' => 'queued']);
    $pendingMail = Mockery::mock(PendingMail::class);
    Mail::shouldReceive('to')->once()->with($booking->customer_email)->andReturn($pendingMail);
    $pendingMail->shouldReceive('send')->once()->with(Mockery::type(TransactionalNotificationEmail::class))
        ->andThrow(new RuntimeException('SMTP password should not be stored in the outbox'));

    (new SendNotificationDelivery($delivery->id))->handle(app(NotificationMessageFactory::class));

    $delivery->refresh();
    expect($delivery->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_attempt_at)->not->toBeNull()
        ->and($delivery->next_attempt_at->isFuture())->toBeTrue()
        ->and($delivery->last_error)->toBe('Email delivery failed (RuntimeException).')
        ->and($delivery->last_error)->not->toContain('password')
        ->and($delivery->last_error)->not->toContain($booking->customer_email);
});

test('scheduled appointment and bundle expiry reminders are generated idempotently', function () {
    $entitlement = makeNotificationTestEntitlement(14);
    makeNotificationTestBooking('ro', $entitlement->id, CarbonImmutable::now('UTC')->addHours(24));
    $outbox = app(NotificationOutboxService::class);

    expect($outbox->queueDueReminders())->toBe(2)
        ->and($outbox->queueDueReminders())->toBe(0)
        ->and(NotificationDelivery::query()->where('event', 'appointment_reminder_24h')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('event', 'bundle_expiry_14d')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('event', 'bundle_expiry_3d')->count())->toBe(0);
});

test('outbox dispatcher queues a due email only once even when the scheduler runs repeatedly', function () {
    Queue::fake();
    $booking = makeNotificationTestBooking();
    $delivery = app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);

    $this->artisan('notifications:dispatch')->assertExitCode(0);
    $this->artisan('notifications:dispatch')->assertExitCode(0);

    expect($delivery->fresh()->status)->toBe('queued');
    Queue::assertPushed(SendNotificationDelivery::class, 1);
});

test('refund and bundle expiry emails use their persisted locale and avoid ineligible refunded entitlements', function () {
    $entitlement = makeNotificationTestEntitlement(3);
    $entitlement->update(['status' => 'refunded']);
    $outbox = app(NotificationOutboxService::class);

    expect($outbox->enqueueEntitlementEvent($entitlement, NotificationEvent::BundleExpiry3Days))->toBeNull()
        ->and(NotificationDelivery::query()->count())->toBe(0);
});
