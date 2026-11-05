<?php

use App\Contracts\CallEvents\CallEventProvider;
use App\Contracts\Notifications\WhatsAppGateway;
use App\Data\CallEvents\CallEventResult;
use App\Exceptions\CallEvents\CallEventProviderException;
use App\Exceptions\NotificationNoLongerEligibleException;
use App\Jobs\CallEvents\CancelBookingCallEvent;
use App\Jobs\CallEvents\CreateBookingCallEvent;
use App\Jobs\CallEvents\RetrieveBookingCallEvent;
use App\Jobs\SendNotificationDelivery;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BookingCallEvent;
use App\Models\NotificationDelivery;
use App\Models\SessionFeedback;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\CallEvents\BookingCallEventService;
use App\Services\CallEvents\OpenWaConfig;
use App\Services\CallEvents\OpenWaHttpClient;
use App\Services\Notifications\NotificationMessageFactory;
use App\Services\Notifications\NotificationOutboxService;
use App\Services\Notifications\WhatsAppMessageFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\FakeCallEventProvider;
use Tests\Support\FakeWhatsAppGateway;

function makeCallEventTestBooking(array $overrides = [], bool $assignTeacher = true): Booking
{
    $teacher = $assignTeacher ? User::factory()->create(['is_teacher' => true, 'teacher_active' => true]) : null;
    $startsAt = CarbonImmutable::now('UTC')->addHours(3);

    return Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'bundle_entitlement_id' => null,
        'free_session_claim_id' => null,
        'teacher_id' => $teacher?->id,
        'teacher_assigned_at' => $teacher ? now() : null,
        'customer_name' => 'Call Event Customer',
        'customer_email' => 'call-event-customer@example.com',
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practise speaking',
        'notes' => null,
        'accepted_policy_version' => '2026-10-25',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'locale' => 'en',
        'hold_expires_at' => null,
        'confirmed_at' => now(),
    ], $overrides));
}

function enableFakeCallEventProvider(FakeCallEventProvider $provider): BookingCallEventService
{
    config(['services.openwa' => [
        'enabled' => true,
        'base_url' => 'https://openwa.test',
        'service_token' => str_repeat('s', 48),
        'connect_timeout' => 5,
        'timeout' => 30,
        'max_attempts' => 5,
        'retry_delays_seconds' => [60, 300, 900, 3600, 14400],
    ]]);
    app()->forgetInstance(OpenWaConfig::class);
    app()->instance(CallEventProvider::class, $provider);

    return app(BookingCallEventService::class);
}

test('OpenWA feature flag is disabled by default and does not create provider records', function () {
    $provider = new FakeCallEventProvider;
    config(['services.openwa.enabled' => false]);
    app()->forgetInstance(OpenWaConfig::class);
    app()->instance(CallEventProvider::class, $provider);

    $event = app(BookingCallEventService::class)->createForBooking(makeCallEventTestBooking()->id, 1);

    expect($event)->toBeNull()
        ->and(BookingCallEvent::query()->count())->toBe(0)
        ->and($provider->createRequests)->toBe([]);
});

test('confirmed assigned booking creates one encrypted call event with stable idempotency and UTC timestamps', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();

    $event = $service->createForBooking($booking->id, 1);

    expect($event)->not->toBeNull()
        ->and($event->status)->toBe('scheduled')
        ->and($event->provider)->toBe('openwa')
        ->and($event->call_type)->toBe('video')
        ->and($event->provider_event_id)->toStartWith('fake-event-')
        ->and($event->join_url)->toBe('https://call.whatsapp.com/fake-call-link')
        ->and($event->scheduled_start_at->toIso8601String())->toBe($booking->starts_at->utc()->toIso8601String())
        ->and($event->scheduled_end_at->toIso8601String())->toBe($booking->ends_at->utc()->toIso8601String())
        ->and($event->idempotency_key)->toMatch('/^booking_call_event_[a-f0-9]{64}$/')
        ->and($provider->createRequests[0]['payload']['timezone'])->toBe('Europe/Bucharest')
        ->and($provider->createRequests[0]['payload']['callType'])->toBe('video');

    $rawStoredLink = DB::table('booking_call_events')->where('id', $event->id)->value('join_url');
    expect($rawStoredLink)->not->toContain('call.whatsapp.com')
        ->and($event->toArray())->not->toHaveKey('join_url')
        ->and($event->toArray())->not->toHaveKey('idempotency_key');
});

test('repeated create jobs for the same booking revision reuse one provider event', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();

    $first = $service->createForBooking($booking->id, 1);
    $second = $service->createForBooking($booking->id, 1);

    expect($second->id)->toBe($first->id)
        ->and($provider->createRequests)->toHaveCount(1)
        ->and(BookingCallEvent::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

test('unconfirmed or unassigned bookings cannot create call events', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $pending = makeCallEventTestBooking(['status' => 'pending', 'confirmed_at' => null]);
    $unassigned = makeCallEventTestBooking([], false);

    expect($service->createForBooking($pending->id, 1))->toBeNull()
        ->and($service->createForBooking($unassigned->id, 1))->toBeNull()
        ->and($provider->createRequests)->toBe([])
        ->and(BookingCallEvent::query()->count())->toBe(0);
});

test('timeout after provider-side creation retries with the same idempotency key without creating a duplicate', function () {
    $provider = new FakeCallEventProvider;
    $provider->createThenThrowOnce = true;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();

    $first = $service->createForBooking($booking->id, 1);
    expect($first->status)->toBe('pending')
        ->and($first->next_attempt_at)->not->toBeNull()
        ->and($provider->events)->toHaveCount(1);

    $this->travel(61)->seconds();
    $second = $service->createForBooking($booking->id, 1);

    expect($second->status)->toBe('scheduled')
        ->and($provider->events)->toHaveCount(1)
        ->and($provider->createRequests[0]['idempotency_key'])->toBe($provider->createRequests[1]['idempotency_key']);
});

test('pending provider response with an event id is reconciled before exposing the join link', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $idempotencyKey = 'booking_call_event_'.substr(hash('sha256', 'openwa:'.$booking->public_reference.':1'), 0, 64);
    $providerEventId = 'fake-event-pending-123456';
    $provider->nextCreateResult = new CallEventResult('pending', $providerEventId);
    $provider->nextRetrieveResult = new CallEventResult('scheduled', $providerEventId, 'https://call.whatsapp.com/reconciled-link', now()->toImmutable());

    $event = $service->createForBooking($booking->id, 1);

    expect($event->status)->toBe('scheduled')
        ->and($event->provider_event_id)->toBe($providerEventId)
        ->and($event->join_url)->toBe('https://call.whatsapp.com/reconciled-link')
        ->and($provider->retrieveRequests)->toContain($providerEventId);
});

test('provider failures use sanitized categories and bounded retry attempts', function () {
    $provider = new FakeCallEventProvider;
    $provider->nextCreateException = new CallEventProviderException('unavailable', true, 'OpenWA service is unavailable.');
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();

    $event = $service->createForBooking($booking->id, 1);
    expect($event->status)->toBe('pending')
        ->and($event->last_error_category)->toBe('unavailable')
        ->and($event->last_error_message)->toBe('Call-event provider is unavailable.')
        ->and($event->last_error_message)->not->toContain($booking->customer_email);

    $event->update(['attempts' => 5, 'next_attempt_at' => now()->subSecond()]);
    $provider->nextCreateException = new CallEventProviderException('unavailable', true, 'OpenWA service is unavailable.');
    $event = $service->createForBooking($booking->id, 1);
    expect($event->status)->toBe('failed')
        ->and($event->next_attempt_at)->toBeNull();
});

test('invalid or non-WhatsApp join URLs are never persisted as scheduled events', function () {
    $provider = new FakeCallEventProvider;
    $provider->nextCreateResult = new CallEventResult('scheduled', 'provider-event-invalid-url', 'https://attacker.example/meeting', now()->toImmutable());
    $service = enableFakeCallEventProvider($provider);
    $event = $service->createForBooking(makeCallEventTestBooking()->id, 1);

    expect($event->status)->toBe('failed')
        ->and($event->join_url)->toBeNull()
        ->and($event->last_error_category)->toBe('invalid_response');
});

test('cancelling a scheduled event removes the protected join link and is idempotent', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $event = $service->createForBooking(makeCallEventTestBooking()->id, 1);

    $cancelled = $service->cancel($event->id);
    $again = $service->cancel($event->id);

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->join_url)->toBeNull()
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($again->status)->toBe('cancelled')
        ->and($provider->cancelRequests)->toHaveCount(1);
});

test('a cancellation requested during event creation is applied as soon as the provider event is recovered', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $idempotencyKey = 'booking_call_event_'.substr(hash('sha256', 'openwa:'.$booking->public_reference.':1'), 0, 64);
    BookingCallEvent::query()->create([
        'booking_id' => $booking->id, 'provider' => 'openwa', 'revision' => 1, 'idempotency_key' => $idempotencyKey,
        'call_type' => 'video', 'scheduled_start_at' => $booking->starts_at, 'scheduled_end_at' => $booking->ends_at,
        'status' => 'cancel_pending', 'cancel_requested_at' => now(), 'attempts' => 1,
    ]);

    $result = $service->createForBooking($booking->id, 1);

    expect($result->status)->toBe('cancelled')
        ->and($result->join_url)->toBeNull()
        ->and($provider->cancelRequests)->toHaveCount(1);
});

test('due call-event operations dispatch create, retrieve, and cancel jobs with bounded retries', function () {
    Queue::fake();
    enableFakeCallEventProvider(new FakeCallEventProvider);
    $booking = makeCallEventTestBooking();
    $event = BookingCallEvent::query()->create([
        'booking_id' => $booking->id, 'provider' => 'openwa', 'revision' => 1, 'idempotency_key' => 'booking_call_event_'.str_repeat('a', 64),
        'call_type' => 'video', 'scheduled_start_at' => $booking->starts_at, 'scheduled_end_at' => $booking->ends_at,
        'status' => 'pending', 'attempts' => 1, 'next_attempt_at' => now()->subMinute(),
    ]);
    $retrieve = BookingCallEvent::query()->create([
        'booking_id' => $booking->id, 'provider' => 'openwa', 'revision' => 2, 'idempotency_key' => 'booking_call_event_'.str_repeat('b', 64),
        'provider_event_id' => 'provider-event-reconcile', 'call_type' => 'video', 'scheduled_start_at' => $booking->starts_at,
        'scheduled_end_at' => $booking->ends_at, 'status' => 'reconciliation_pending', 'attempts' => 1, 'next_attempt_at' => now()->subMinute(),
    ]);
    $cancel = BookingCallEvent::query()->create([
        'booking_id' => $booking->id, 'provider' => 'openwa', 'revision' => 3, 'idempotency_key' => 'booking_call_event_'.str_repeat('c', 64),
        'provider_event_id' => 'provider-event-cancel', 'call_type' => 'video', 'scheduled_start_at' => $booking->starts_at,
        'scheduled_end_at' => $booking->ends_at, 'status' => 'cancel_pending', 'attempts' => 1, 'next_attempt_at' => now()->subMinute(),
    ]);

    $this->artisan('call-events:dispatch')->assertExitCode(0);

    Queue::assertPushed(CreateBookingCallEvent::class, fn (CreateBookingCallEvent $job): bool => $job->bookingId === $booking->id && $job->revision === 1);
    Queue::assertPushed(RetrieveBookingCallEvent::class, fn (RetrieveBookingCallEvent $job): bool => $job->eventId === $retrieve->id);
    Queue::assertPushed(CancelBookingCallEvent::class, fn (CancelBookingCallEvent $job): bool => $job->eventId === $cancel->id);
    expect($event->fresh()->status)->toBe('queued')
        ->and($retrieve->fresh()->status)->toBe('queued')
        ->and($cancel->fresh()->status)->toBe('queued');
});

test('customer and assigned teacher can retrieve the call link but unrelated users cannot', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $service->createForBooking($booking->id, 1);
    $teacher = $booking->teacher()->firstOrFail();
    $customer = User::factory()->create(['email' => $booking->customer_email]);
    $unrelated = User::factory()->create();
    $otherTeacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);

    $this->actingAs($customer)->getJson(route('booking.call-link', $booking->public_reference))
        ->assertOk()->assertJsonPath('join_url', 'https://call.whatsapp.com/fake-call-link')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->actingAs($teacher)->getJson(route('booking.call-link', $booking->public_reference))->assertOk();
    $this->actingAs($unrelated)->getJson(route('booking.call-link', $booking->public_reference))->assertForbidden();
    $this->actingAs($otherTeacher)->getJson(route('booking.call-link', $booking->public_reference))->assertForbidden();
});

test('unauthenticated users and bookings without a ready call link cannot retrieve the URL', function () {
    $booking = makeCallEventTestBooking();
    $this->get(route('booking.call-link', $booking->public_reference))->assertRedirect(route('login'));

    $customer = User::factory()->create(['email' => $booking->customer_email]);
    $this->actingAs($customer)->getJson(route('booking.call-link', $booking->public_reference))->assertNotFound();
});

test('OpenWA config validates private endpoints, credentials, timeout bounds, and retry schedules', function () {
    expect(OpenWaConfig::fromArray(['enabled' => false])->enabled)->toBeFalse();
    expect(OpenWaConfig::fromArray(['enabled' => true, 'base_url' => 'https://openwa.example.com', 'service_token' => str_repeat('x', 40)])->enabled)->toBeTrue();
    expect(OpenWaConfig::fromArray(['enabled' => true, 'base_url' => 'http://openwa.railway.internal:8080', 'service_token' => str_repeat('x', 40)])->baseUrl)->toBe('http://openwa.railway.internal:8080');

    expect(fn () => OpenWaConfig::fromArray(['enabled' => true, 'base_url' => 'http://openwa.example.com', 'service_token' => str_repeat('x', 40)]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OpenWaConfig::fromArray(['enabled' => true, 'base_url' => 'https://openwa.example.com', 'service_token' => 'weak']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OpenWaConfig::fromArray(['enabled' => true, 'base_url' => 'https://openwa.example.com', 'service_token' => str_repeat('x', 40), 'connect_timeout' => 15, 'timeout' => 10]))->toThrow(InvalidArgumentException::class);
});

test('OpenWA HTTP client sends bearer and idempotency headers and parses the normalized provider response', function () {
    config(['services.openwa' => [
        'enabled' => true, 'base_url' => 'https://openwa.test', 'service_token' => str_repeat('t', 48),
        'connect_timeout' => 5, 'timeout' => 30, 'max_attempts' => 5, 'retry_delays_seconds' => [60, 300, 900],
    ]]);
    app()->forgetInstance(OpenWaConfig::class);
    app()->forgetInstance(OpenWaHttpClient::class);
    Http::fake([
        'https://openwa.test/health/ready' => Http::response(['status' => 'ready'], 200),
        'https://openwa.test/v1/call-events' => Http::response([
            'providerEventId' => 'openwa-event-12345678', 'joinUrl' => 'https://call.whatsapp.com/test-link',
            'status' => 'scheduled', 'createdAt' => '2026-11-05T10:00:00.000Z',
        ], 201),
    ]);

    $client = app(OpenWaHttpClient::class);
    expect($client->isReady())->toBeTrue();
    $result = $client->createEvent(['externalBookingReference' => 'booking-123'], 'booking_call_event_'.str_repeat('a', 64));

    expect($result->status)->toBe('scheduled')
        ->and($result->providerEventId)->toBe('openwa-event-12345678')
        ->and($result->joinUrl)->toBe('https://call.whatsapp.com/test-link')
        ->and($result->createdAt?->toIso8601String())->toBe('2026-11-05T10:00:00+00:00');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openwa.test/v1/call-events'
        && $request->hasHeader('Authorization', 'Bearer '.str_repeat('t', 48))
        && $request->hasHeader('Idempotency-Key', 'booking_call_event_'.str_repeat('a', 64)));
});

test('OpenWA HTTP client maps provider outages to retryable sanitized errors', function () {
    config(['services.openwa' => [
        'enabled' => true, 'base_url' => 'https://openwa.test', 'service_token' => str_repeat('t', 48),
        'connect_timeout' => 5, 'timeout' => 30, 'max_attempts' => 5, 'retry_delays_seconds' => [60],
    ]]);
    app()->forgetInstance(OpenWaConfig::class);
    app()->forgetInstance(OpenWaHttpClient::class);
    Http::fake(['*' => Http::response(['error' => 'secret provider details'], 503)]);

    try {
        app(OpenWaHttpClient::class)->createEvent([], 'booking_call_event_'.str_repeat('a', 64));
        test()->fail('Expected a provider exception.');
    } catch (CallEventProviderException $exception) {
        expect($exception->category)->toBe('unavailable')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->getMessage())->not->toContain('secret provider details');
    }
});

test('OpenWA HTTP client does not expose raw transport exception details', function () {
    config(['services.openwa' => [
        'enabled' => true, 'base_url' => 'https://openwa.test', 'service_token' => str_repeat('t', 48),
        'connect_timeout' => 5, 'timeout' => 30, 'max_attempts' => 5, 'retry_delays_seconds' => [60],
    ]]);
    app()->forgetInstance(OpenWaConfig::class);
    app()->forgetInstance(OpenWaHttpClient::class);
    Http::fake(['*' => function (Request $request) {
        throw new ConnectionException('private socket details');
    }]);

    try {
        app(OpenWaHttpClient::class)->createEvent([], 'booking_call_event_'.str_repeat('a', 64));
        test()->fail('Expected a transport exception.');
    } catch (CallEventProviderException $exception) {
        expect($exception->category)->toBe('timeout')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->getMessage())->not->toContain('private socket details');
    }
});

test('OpenWA HTTP client retrieves and cancels events using normalized responses', function () {
    config(['services.openwa' => [
        'enabled' => true, 'base_url' => 'https://openwa.test', 'service_token' => str_repeat('t', 48),
        'connect_timeout' => 5, 'timeout' => 30, 'max_attempts' => 5, 'retry_delays_seconds' => [60],
    ]]);
    app()->forgetInstance(OpenWaConfig::class);
    app()->forgetInstance(OpenWaHttpClient::class);
    Http::fake([
        'https://openwa.test/v1/call-events/openwa-event-12345678' => Http::sequence()
            ->push(['providerEventId' => 'openwa-event-12345678', 'joinUrl' => 'https://call.whatsapp.com/retrieved-link', 'status' => 'scheduled', 'createdAt' => '2026-11-05T10:00:00Z'], 200)
            ->push(['providerEventId' => 'openwa-event-12345678', 'status' => 'cancelled'], 200),
    ]);

    $client = app(OpenWaHttpClient::class);
    $retrieved = $client->retrieveEvent('openwa-event-12345678');
    expect($retrieved?->status)->toBe('scheduled')
        ->and($retrieved?->joinUrl)->toBe('https://call.whatsapp.com/retrieved-link')
        ->and($client->cancelEvent('openwa-event-12345678'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/openwa-event-12345678'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/openwa-event-12345678'));
});

test('OpenWA config rejects endpoint credentials and query strings', function () {
    expect(fn () => OpenWaConfig::fromArray(['enabled' => true, 'base_url' => 'https://user:pass@openwa.example.com', 'service_token' => str_repeat('x', 40)]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OpenWaConfig::fromArray(['enabled' => true, 'base_url' => 'https://openwa.example.com?token=secret', 'service_token' => str_repeat('x', 40)]))->toThrow(InvalidArgumentException::class);
});

test('latest pending revision never falls back to an older scheduled call link', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $service->createForBooking($booking->id, 1);
    BookingCallEvent::query()->create([
        'booking_id' => $booking->id,
        'provider' => 'openwa',
        'revision' => 2,
        'idempotency_key' => 'booking_call_event_'.str_repeat('d', 64),
        'call_type' => 'video',
        'scheduled_start_at' => $booking->starts_at->addDay(),
        'scheduled_end_at' => $booking->ends_at->addDay(),
        'status' => 'pending',
    ]);
    $customer = User::factory()->create(['email' => $booking->customer_email]);

    $this->actingAs($customer)->getJson(route('booking.call-link', $booking->public_reference))->assertNotFound();
});

test('unverified customer accounts cannot retrieve a booking call link', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $service->createForBooking($booking->id, 1);
    $unverifiedCustomer = User::factory()->unverified()->create(['email' => $booking->customer_email]);

    $this->actingAs($unverifiedCustomer)->getJson(route('booking.call-link', $booking->public_reference))->assertForbidden();
});

test('invalid booking phone numbers fail before contacting the provider', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    DB::table('bookings')->where('id', $booking->id)->update(['customer_phone' => 'not-a-phone']);

    $event = $service->createForBooking($booking->id, 1);

    expect($event->status)->toBe('failed')
        ->and($event->last_error_category)->toBe('invalid_destination')
        ->and($provider->createRequests)->toBe([]);
});

test('provider readiness is false when the adapter is unavailable', function () {
    $provider = new FakeCallEventProvider;
    $provider->ready = false;
    $service = enableFakeCallEventProvider($provider);

    expect($service->isReady())->toBeFalse();
});

test('staff accounts cannot retrieve a customer call link outside the customer and teacher channels', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $service->createForBooking($booking->id, 1);
    $staff = User::factory()->create(['is_staff' => true]);

    $this->actingAs($staff)->getJson(route('booking.call-link', $booking->public_reference))->assertForbidden();
});

test('OpenWA HTTP client preserves uncertain create outcomes and treats missing provider events explicitly', function () {
    config(['services.openwa' => [
        'enabled' => true, 'base_url' => 'https://openwa.test', 'service_token' => str_repeat('t', 48),
        'connect_timeout' => 5, 'timeout' => 30, 'max_attempts' => 5, 'retry_delays_seconds' => [60],
    ]]);
    app()->forgetInstance(OpenWaConfig::class);
    app()->forgetInstance(OpenWaHttpClient::class);
    Http::fake([
        'https://openwa.test/v1/call-events' => Http::response([
            'providerEventId' => null, 'joinUrl' => null, 'status' => 'submission_unconfirmed',
        ], 202),
        'https://openwa.test/v1/call-events/missing-event' => Http::response(['error' => 'not found'], 404),
    ]);

    $client = app(OpenWaHttpClient::class);
    $pending = $client->createEvent([], 'booking_call_event_'.str_repeat('a', 64));

    expect($pending->status)->toBe('pending')
        ->and($pending->providerEventId)->toBeNull()
        ->and($client->retrieveEvent('missing-event'))->toBeNull()
        ->and($client->cancelEvent('missing-event'))->toBeFalse();
});

test('a cancellation after an uncertain create recovers the same provider event and cancels it', function () {
    $provider = new FakeCallEventProvider;
    $provider->createThenThrowOnce = true;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $event = $service->createForBooking($booking->id, 1);
    $booking->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    $cancelPending = $service->cancel($event->id);
    $cancelled = $service->createForBooking($booking->id, 1);

    expect($cancelPending->status)->toBe('cancel_pending')
        ->and($cancelled->status)->toBe('cancelled')
        ->and($cancelled->join_url)->toBeNull()
        ->and($provider->events)->toHaveCount(1)
        ->and($provider->cancelRequests)->toHaveCount(1);
});

test('reconciliation job stores the latest provider link after an uncertain result', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $event = $service->createForBooking($booking->id, 1);
    $provider->events[$event->idempotency_key] = new CallEventResult('scheduled', $event->provider_event_id, 'https://call.whatsapp.com/refreshed-link', now()->toImmutable());
    $event->update(['status' => 'reconciliation_pending', 'join_url' => null, 'next_attempt_at' => now()]);

    $reconciled = $service->retrieve($event->id);

    expect($reconciled->status)->toBe('scheduled')
        ->and($reconciled->join_url)->toBe('https://call.whatsapp.com/refreshed-link')
        ->and($provider->retrieveRequests)->toContain($event->provider_event_id);
});

test('call-event jobs are configured to dispatch after database commits', function () {
    expect((new CreateBookingCallEvent(1, 1))->afterCommit)->toBeTrue()
        ->and((new RetrieveBookingCallEvent(1))->afterCommit)->toBeTrue()
        ->and((new CancelBookingCallEvent(1))->afterCommit)->toBeTrue();
});

test('cancelled bookings never expose an existing scheduled call link', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $service->createForBooking($booking->id, 1);
    $customer = User::factory()->create(['email' => $booking->customer_email]);
    $booking->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    $this->actingAs($customer)->getJson(route('booking.call-link', $booking->public_reference))->assertNotFound();
});

test('teacher reassignment invalidates access to the previous call-event revision', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $service->createForBooking($booking->id, 1);
    $previousTeacher = $booking->teacher()->firstOrFail();
    $newTeacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $customer = User::factory()->create(['email' => $booking->customer_email]);
    $booking->update(['teacher_id' => $newTeacher->id, 'teacher_assigned_at' => now()]);

    $this->actingAs($customer)->getJson(route('booking.call-link', $booking->public_reference))->assertNotFound();
    $this->actingAs($newTeacher)->getJson(route('booking.call-link', $booking->public_reference))->assertNotFound();
    $this->actingAs($previousTeacher)->getJson(route('booking.call-link', $booking->public_reference))->assertForbidden();
});

test('scheduled call event creates independent customer and teacher email deliveries with the same private link', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $event = $service->createForBooking($booking->id, 1);

    $deliveries = NotificationDelivery::query()->where('booking_call_event_id', $event->id)->orderBy('event')->get();
    $customerDelivery = $deliveries->firstWhere('event', 'customer_call_link_ready');
    $teacherDelivery = $deliveries->firstWhere('event', 'teacher_call_link_ready');
    $messages = app(NotificationMessageFactory::class);
    $customerMessage = $messages->make($customerDelivery);
    $teacherMessage = $messages->make($teacherDelivery);

    expect($deliveries)->toHaveCount(2)
        ->and($customerDelivery->recipient_type)->toBe('customer')
        ->and($teacherDelivery->recipient_type)->toBe('teacher')
        ->and($teacherDelivery->recipient_user_id)->toBe($booking->teacher_id)
        ->and($customerMessage['recipient'])->toBe($booking->customer_email)
        ->and($teacherMessage['recipient'])->toBe($booking->teacher->email)
        ->and($customerMessage['ctaUrl'])->toBe($event->join_url)
        ->and(collect($customerMessage['details'])->firstWhere('label', 'How to join')['value'])->not->toBeEmpty()
        ->and(collect($teacherMessage['details'])->firstWhere('label', 'WhatsApp call link')['value'])->toBe($event->join_url)
        ->and($customerDelivery->metadata)->not->toHaveKey('join_url')
        ->and($teacherDelivery->metadata)->not->toHaveKey('join_url');
});

test('WhatsApp call-link deliveries respect separate customer and teacher consent and avoid shared-number leakage', function () {
    $provider = new FakeCallEventProvider;
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_active' => true,
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
    ]);
    $booking = makeCallEventTestBooking(['teacher_id' => $teacher->id]);
    WhatsAppContact::recordConsent($booking->customer_phone, 'test_customer_consent');
    config(['services.whatsapp.enabled' => true]);
    $service = enableFakeCallEventProvider($provider);

    $event = $service->createForBooking($booking->id, 1);
    $deliveries = NotificationDelivery::query()->where('booking_call_event_id', $event->id)->where('channel', 'whatsapp')->get();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->first()->event)->toBe('customer_call_link_ready');

    $message = app(WhatsAppMessageFactory::class)->make($deliveries->first());
    expect($message['recipient'])->toBe($booking->customer_phone)
        ->and($message['template'])->toBe('easyenglish_call_link_ready')
        ->and($message['components'][0]['parameters'])->toHaveCount(7)
        ->and($message['components'][0]['parameters'][5]['text'])->toBe($event->join_url)
        ->and($message['components'][0]['parameters'][6]['text'])->not->toBeEmpty();
});

test('WhatsApp teacher call-link delivery uses the verified teacher number and teacher-only template', function () {
    $provider = new FakeCallEventProvider;
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_active' => true,
        'phone' => '+40722987654',
        'phone_verified_at' => now(),
    ]);
    $booking = makeCallEventTestBooking(['teacher_id' => $teacher->id]);
    WhatsAppContact::recordConsent($booking->customer_phone, 'test_customer_consent');
    WhatsAppContact::recordConsent($teacher->phone, 'test_teacher_consent');
    config(['services.whatsapp.enabled' => true]);
    $service = enableFakeCallEventProvider($provider);

    $event = $service->createForBooking($booking->id, 1);
    $delivery = NotificationDelivery::query()
        ->where('booking_call_event_id', $event->id)
        ->where('channel', 'whatsapp')
        ->where('event', 'teacher_call_link_ready')
        ->firstOrFail();
    $message = app(WhatsAppMessageFactory::class)->make($delivery);

    expect($delivery->recipient_type)->toBe('teacher')
        ->and($delivery->recipient_user_id)->toBe($teacher->id)
        ->and($message['recipient'])->toBe($teacher->phone)
        ->and($message['template'])->toBe('easyenglish_teacher_call_link_ready')
        ->and($message['components'][0]['parameters'][11]['text'])->toBe($event->join_url);
});

test('repeated call-ready handling creates no duplicate recipient deliveries', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $event = $service->createForBooking($booking->id, 1);

    app(NotificationOutboxService::class)->enqueueCallLinkReady($booking->fresh(), $event->fresh());
    app(NotificationOutboxService::class)->enqueueCallLinkReady($booking->fresh(), $event->fresh());

    expect(NotificationDelivery::query()->where('booking_call_event_id', $event->id)->count())->toBe(2);
});

test('teacher booking page is restricted to the currently assigned teacher and accepts feedback after the session', function () {
    $booking = makeCallEventTestBooking();
    $teacher = $booking->teacher;
    $otherTeacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);

    $this->actingAs($otherTeacher)->get(route('teacher.bookings.show', $booking->public_reference))->assertNotFound();
    $this->actingAs($teacher)->get(route('teacher.bookings.show', $booking->public_reference))->assertOk()
        ->assertSee($booking->customer_email)
        ->assertSee('Session feedback');
    $this->actingAs($teacher)->post(route('teacher.bookings.feedback', $booking->public_reference), [
        'scores' => ['fluency' => 75], 'feedback' => 'Good progress and clear pronunciation practice.',
    ])->assertUnprocessable();

    $this->travel(4)->hours();
    $this->actingAs($teacher)->post(route('teacher.bookings.feedback', $booking->public_reference), [
        'scores' => ['vocabulary' => 70, 'fluency' => 75],
        'feedback' => 'Good progress and clear pronunciation practice.',
    ])->assertRedirect(route('teacher.bookings.show', $booking->public_reference));

    expect(SessionFeedback::query()->where('booking_id', $booking->id)->where('metric_key', 'vocabulary')->value('current_score'))->toBe(70)
        ->and(SessionFeedback::query()->where('booking_id', $booking->id)->where('metric_key', 'fluency')->value('current_score'))->toBe(75)
        ->and(SessionFeedback::query()->where('booking_id', $booking->id)->where('metric_key', 'overall')->value('feedback'))->toBe('Good progress and clear pronunciation practice.');
});

test('terminal call setup failure creates an audit entry and a database alert for staff', function () {
    $provider = new FakeCallEventProvider;
    $provider->nextCreateException = new CallEventProviderException('authentication', false, 'Provider token must not be exposed.');
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $staff = User::factory()->create(['is_staff' => true]);

    $event = $service->createForBooking($booking->id, 1);

    expect($event->status)->toBe('failed')
        ->and($booking->fresh()->status)->toBe('confirmed')
        ->and($event->last_error_message)->toBe('Call-event provider authentication failed.')
        ->and(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $staff->id)->value('data'))->toContain('WhatsApp call setup needs attention')
        ->and(BookingAudit::query()->where('booking_id', $booking->id)->where('action', 'call_event.failed')->exists())->toBeTrue();
});

test('staff can inspect call-event health while non-staff cannot access the operations page', function () {
    $ordinary = User::factory()->create();
    $this->actingAs($ordinary)->get('/admin/call-events')->assertForbidden();

    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff)->get('/admin/call-events')->assertOk();
});

test('teacher cancellation notifications target only the assigned teacher and include the cancellation reason', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $teacher = $booking->teacher;
    $booking->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => 'customer']);

    $delivery = app(NotificationOutboxService::class)
        ->enqueueTeacherBookingCancelled($booking->fresh(), $teacher->id, 'customer_cancelled')[0];
    $message = app(NotificationMessageFactory::class)->make($delivery);

    expect($delivery->recipient_type)->toBe('teacher')
        ->and($delivery->recipient_user_id)->toBe($teacher->id)
        ->and($message['recipient'])->toBe($teacher->email)
        ->and(collect($message['details'])->firstWhere('label', 'Update')['value'])->toBe('The booking has been cancelled.')
        ->and($message['ctaUrl'])->toContain('/teacher/bookings/'.$booking->public_reference);
});

test('teacher cancellation WhatsApp uses teacher consent and never sends teacher-only content to a shared number', function () {
    $provider = new FakeCallEventProvider;
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_active' => true,
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
    ]);
    $booking = makeCallEventTestBooking(['teacher_id' => $teacher->id]);
    $booking->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => 'customer']);
    WhatsAppContact::recordConsent($booking->customer_phone, 'test_shared_phone_consent');
    config(['services.whatsapp.enabled' => true]);
    enableFakeCallEventProvider($provider);

    app(NotificationOutboxService::class)
        ->enqueueTeacherBookingCancelled($booking->fresh(), $teacher->id, 'customer_cancelled');

    expect(NotificationDelivery::query()->where('event', 'teacher_booking_cancelled')->where('channel', 'whatsapp')->count())->toBe(0)
        ->and(NotificationDelivery::query()->where('event', 'teacher_booking_cancelled')->where('channel', 'email')->count())->toBe(1);
});

test('queued create for a booking cancelled before the worker starts is a safe no-op', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => 'customer']);

    expect($service->createForBooking($booking->id, 1))->toBeNull()
        ->and(BookingCallEvent::query()->where('booking_id', $booking->id)->count())->toBe(0)
        ->and($provider->createRequests)->toBe([]);
});

test('queued create for an obsolete booking revision is ignored and only the current revision is created', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $newStart = $booking->starts_at->addDay();
    $booking->update([
        'starts_at' => $newStart,
        'ends_at' => $newStart->addMinutes(15),
        'reschedule_count' => 1,
    ]);

    expect($service->createForBooking($booking->id, 1))->toBeNull()
        ->and($provider->createRequests)->toBe([]);

    $current = $service->createForBooking($booking->id, 2);
    expect($current?->status)->toBe('scheduled')
        ->and($current?->revision)->toBe(2)
        ->and($provider->events)->toHaveCount(1);
});

test('teacher WhatsApp delivery worker sends to the teacher contact and updates only that recipient record', function () {
    $provider = new FakeCallEventProvider;
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_active' => true,
        'phone' => '+40722987654',
        'phone_verified_at' => now(),
    ]);
    $booking = makeCallEventTestBooking(['teacher_id' => $teacher->id]);
    WhatsAppContact::recordConsent($teacher->phone, 'teacher_booking_consent');
    config(['services.whatsapp.enabled' => true]);
    $service = enableFakeCallEventProvider($provider);
    $event = $service->createForBooking($booking->id, 1);
    $delivery = NotificationDelivery::query()
        ->where('booking_call_event_id', $event->id)
        ->where('channel', 'whatsapp')
        ->where('event', 'teacher_call_link_ready')
        ->firstOrFail();
    $delivery->update(['status' => 'queued']);
    $customerEmailDelivery = NotificationDelivery::query()
        ->where('booking_call_event_id', $event->id)
        ->where('event', 'customer_call_link_ready')
        ->firstOrFail();
    $fakeGateway = new FakeWhatsAppGateway;
    app()->instance(WhatsAppGateway::class, $fakeGateway);

    (new SendNotificationDelivery($delivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        $fakeGateway,
    );

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->recipient_user_id)->toBe($teacher->id)
        ->and($fakeGateway->sent)->toHaveCount(1)
        ->and($fakeGateway->sent[0]['recipient'])->toBe($teacher->phone)
        ->and($fakeGateway->sent[0]['template'])->toBe('easyenglish_teacher_call_link_ready')
        ->and($customerEmailDelivery->fresh()->status)->toBe('pending')
        ->and(BookingCallEvent::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

test('queued call-link notifications are skipped when the booking has moved to another revision', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $event = $service->createForBooking($booking->id, 1);
    $delivery = NotificationDelivery::query()
        ->where('booking_call_event_id', $event->id)
        ->where('event', 'customer_call_link_ready')
        ->firstOrFail();
    $newStart = $booking->starts_at->addDay();
    $booking->update(['starts_at' => $newStart, 'ends_at' => $newStart->addMinutes(15), 'reschedule_count' => 1]);

    expect(fn () => app(NotificationMessageFactory::class)->make($delivery))
        ->toThrow(NotificationNoLongerEligibleException::class);
});

test('teacher booking page hides a call link immediately when the booking is cancelled', function () {
    $provider = new FakeCallEventProvider;
    $service = enableFakeCallEventProvider($provider);
    $booking = makeCallEventTestBooking();
    $event = $service->createForBooking($booking->id, 1);
    $teacher = $booking->teacher;
    $booking->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => 'customer']);

    $this->actingAs($teacher)
        ->get(route('teacher.bookings.show', $booking->public_reference))
        ->assertOk()
        ->assertDontSee($event->join_url);
});

test('teacher receives an independent booking confirmation even before OpenWA creates a call link', function () {
    $booking = makeCallEventTestBooking();
    $teacher = $booking->teacher;
    DB::table('users')->where('id', $teacher->id)->update(['phone' => '+40722987654', 'phone_verified_at' => now()]);
    $teacher = User::query()->findOrFail($teacher->id);
    expect(DB::table('users')->where('id', $teacher->id)->value('phone'))->toBe('+40722987654');
    WhatsAppContact::recordConsent($teacher->phone, 'teacher_confirmation_consent');
    config(['services.whatsapp.enabled' => true]);

    $delivery = app(NotificationOutboxService::class)->enqueueTeacherBookingConfirmed($booking);
    $message = app(NotificationMessageFactory::class)->make($delivery);
    $whatsappDelivery = NotificationDelivery::query()
        ->where('event', 'teacher_booking_confirmed')
        ->where('channel', 'whatsapp')
        ->firstOrFail();
    $whatsappMessage = app(WhatsAppMessageFactory::class)->make($whatsappDelivery);

    expect($delivery->recipient_type)->toBe('teacher')
        ->and($message['recipient'])->toBe($teacher->email)
        ->and(collect($message['details'])->firstWhere('label', 'Customer phone')['value'])->toBe($booking->customer_phone)
        ->and($message['ctaUrl'])->toContain('/teacher/bookings/'.$booking->public_reference)
        ->and($whatsappMessage['recipient'])->toBe($teacher->phone)
        ->and($whatsappMessage['template'])->toBe('easyenglish_teacher_booking_confirmation')
        ->and($whatsappMessage['components'][0]['parameters'])->toHaveCount(12);
});

test('teacher receives the previous and new appointment time when a booking is rescheduled', function () {
    $booking = makeCallEventTestBooking();
    $teacher = $booking->teacher;
    $previousStart = $booking->starts_at;
    $newStart = $previousStart->addDay();
    $booking->update([
        'starts_at' => $newStart,
        'ends_at' => $newStart->addMinutes(15),
        'reschedule_count' => 1,
    ]);
    DB::table('users')->where('id', $teacher->id)->update(['phone' => '+40722987654', 'phone_verified_at' => now()]);
    $teacher = User::query()->findOrFail($teacher->id);
    WhatsAppContact::recordConsent($teacher->phone, 'teacher_reschedule_consent');
    config(['services.whatsapp.enabled' => true]);

    $delivery = app(NotificationOutboxService::class)->enqueueTeacherBookingRescheduled($booking->fresh(), $teacher->id, $previousStart);
    $message = app(NotificationMessageFactory::class)->make($delivery);
    $whatsappDelivery = NotificationDelivery::query()
        ->where('event', 'teacher_booking_rescheduled')
        ->where('channel', 'whatsapp')
        ->firstOrFail();
    $whatsappMessage = app(WhatsAppMessageFactory::class)->make($whatsappDelivery);

    expect($message['recipient'])->toBe($teacher->email)
        ->and(collect($message['details'])->firstWhere('label', 'Previous appointment time')['value'])->toContain($previousStart->setTimezone('Europe/Bucharest')->format('d.m.Y'))
        ->and($whatsappMessage['template'])->toBe('easyenglish_teacher_booking_rescheduled')
        ->and($whatsappMessage['components'][0]['parameters'])->toHaveCount(11)
        ->and($whatsappMessage['components'][0]['parameters'][5]['text'])->toBe($previousStart->setTimezone('Europe/Bucharest')->format('H:i'))
        ->and($whatsappMessage['components'][0]['parameters'][7]['text'])->toBe($newStart->setTimezone('Europe/Bucharest')->format('H:i'));
});

test('a transient teacher WhatsApp failure retries only that recipient without creating another call event', function () {
    $provider = new FakeCallEventProvider;
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_active' => true,
        'phone' => '+40722987654',
        'phone_verified_at' => now(),
    ]);
    $booking = makeCallEventTestBooking(['teacher_id' => $teacher->id]);
    WhatsAppContact::recordConsent($teacher->phone, 'teacher_delivery_retry_consent');
    config(['services.whatsapp.enabled' => true]);
    $service = enableFakeCallEventProvider($provider);
    $event = $service->createForBooking($booking->id, 1);
    $teacherDelivery = NotificationDelivery::query()
        ->where('booking_call_event_id', $event->id)
        ->where('event', 'teacher_call_link_ready')
        ->where('channel', 'whatsapp')
        ->firstOrFail();
    $customerDelivery = NotificationDelivery::query()
        ->where('booking_call_event_id', $event->id)
        ->where('event', 'customer_call_link_ready')
        ->where('channel', 'email')
        ->firstOrFail();
    $teacherDelivery->update(['status' => 'queued']);
    $gateway = Mockery::mock(WhatsAppGateway::class);
    $gateway->shouldReceive('sendTemplate')->once()->andThrow(new RuntimeException('Temporary provider failure'));

    (new SendNotificationDelivery($teacherDelivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        $gateway,
    );

    expect($teacherDelivery->fresh()->status)->toBe('pending')
        ->and($teacherDelivery->fresh()->attempts)->toBe(1)
        ->and($teacherDelivery->fresh()->next_attempt_at)->not->toBeNull()
        ->and($customerDelivery->fresh()->status)->toBe('pending')
        ->and(BookingCallEvent::query()->where('booking_id', $booking->id)->count())->toBe(1)
        ->and($event->fresh()->status)->toBe('scheduled');
});
