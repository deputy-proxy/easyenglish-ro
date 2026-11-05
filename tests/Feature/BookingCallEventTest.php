<?php

use App\Contracts\CallEvents\CallEventProvider;
use App\Data\CallEvents\CallEventResult;
use App\Exceptions\CallEvents\CallEventProviderException;
use App\Jobs\CallEvents\CancelBookingCallEvent;
use App\Jobs\CallEvents\CreateBookingCallEvent;
use App\Jobs\CallEvents\RetrieveBookingCallEvent;
use App\Models\Booking;
use App\Models\BookingCallEvent;
use App\Models\User;
use App\Services\CallEvents\BookingCallEventService;
use App\Services\CallEvents\OpenWaConfig;
use App\Services\CallEvents\OpenWaHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\FakeCallEventProvider;

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

    expect(fn () => $service->createForBooking($pending->id, 1))->toThrow(DomainException::class)
        ->and(fn () => $service->createForBooking($unassigned->id, 1))->toThrow(DomainException::class)
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
