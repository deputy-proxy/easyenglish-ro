<?php

use App\Contracts\Notifications\WhatsAppGateway;
use App\Data\Notifications\WhatsAppTemplateMessage;
use App\Jobs\SendNotificationDelivery;
use App\Models\Booking;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Notifications\MetaWhatsAppCloudApiGateway;
use App\Services\Notifications\NotificationMessageFactory;
use App\Services\Notifications\NotificationOutboxService;
use App\Services\Notifications\OpenWaMessageReconciliationService;
use App\Services\Notifications\OpenWaMessageRenderer;
use App\Services\Notifications\OpenWaWhatsAppGateway;
use App\Services\Notifications\WhatsAppMessageFactory;
use App\Services\Notifications\WhatsAppProviderConfiguration;
use App\Services\Notifications\WhatsAppWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakeWhatsAppGateway;

function makeWhatsAppBooking(string $startsIn = '+3 days'): Booking
{
    $startsAt = CarbonImmutable::now('UTC')->modify($startsIn);

    return Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_name' => 'WhatsApp Test Customer',
        'customer_email' => 'whatsapp-test@example.com',
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practice conversation',
        'notes' => null,
        'accepted_policy_version' => '2026-10-25',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'locale' => 'en',
        'hold_expires_at' => null,
        'confirmed_at' => now(),
    ]);
}

function enableWhatsAppForTests(): void
{
    config([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.api_version' => 'v26.0',
        'services.whatsapp.phone_number_id' => '123456789',
        'services.whatsapp.access_token' => 'test-access-token',
        'services.whatsapp.app_secret' => 'meta-test-secret',
        'services.whatsapp.verify_token' => 'verify-test-token',
    ]);
    $fake = new FakeWhatsAppGateway;
    app()->instance(WhatsAppGateway::class, $fake);
    app()->instance(FakeWhatsAppGateway::class, $fake);
}

test('WhatsApp messages are queued only after explicit service consent and use a separate idempotent channel record', function () {
    enableWhatsAppForTests();
    $booking = makeWhatsAppBooking();
    $outbox = app(NotificationOutboxService::class);
    $outbox->enqueueBookingConfirmation($booking);

    expect(NotificationDelivery::query()->where('channel', 'whatsapp')->count())->toBe(0);

    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    $outbox->enqueueBookingConfirmation($booking);
    $outbox->enqueueBookingConfirmation($booking);

    expect(NotificationDelivery::query()->where('channel', 'whatsapp')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('channel', 'email')->count())->toBe(1)
        ->and(WhatsAppContact::query()->firstOrFail()->consent_source)->toBe('booking_form');
});

test('WhatsApp worker sends an approved template and persists provider message ID without placing a call', function () {
    enableWhatsAppForTests();
    Mail::fake();
    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $delivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
    $delivery->update(['status' => 'queued']);

    (new SendNotificationDelivery($delivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        app(WhatsAppGateway::class),
    );

    $delivery->refresh();
    $fake = app(FakeWhatsAppGateway::class);
    expect($delivery->status)->toBe('sent')
        ->and($delivery->provider)->toBe('meta')
        ->and($delivery->provider_status)->toBe('accepted')
        ->and($delivery->provider_message_id)->toStartWith('wamid.')
        ->and($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]['template'])->toBe('easyenglish_intro_booking_confirmation')
        ->and($fake->sent[0]['language'])->toBe('en_US')
        ->and($fake->sent[0]['components'][0]['parameters'][0]['text'])->toBe('WhatsApp Test Customer');
});

test('WhatsApp service consent is visible and required before a booking can be submitted', function () {
    $this->actingAs(User::factory()->create(['email' => 'no-consent@example.com', 'phone' => '+40722123456']));
    $this->withSession(['locale' => 'en'])->get('/book?type=intro')->assertOk()
        ->assertSee('name="whatsapp_service_consent"', false)
        ->assertSee('No marketing messages will be sent.');

    $this->from('/book?type=intro')->post('/book', [
        'type' => 'intro',
        'starts_at' => CarbonImmutable::now('Europe/Bucharest')->addDays(2)->toIso8601String(),
        'name' => 'No Consent Customer',
        'email' => 'no-consent@example.com',
        'phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practise speaking',
        'accepted_policy' => '1',
    ])->assertRedirect('/book?type=intro')->assertSessionHasErrors('whatsapp_service_consent');

    expect(WhatsAppContact::query()->count())->toBe(0);
});

test('Meta Cloud API gateway sends a template request with bearer token and never sends free-form messages', function () {
    enableWhatsAppForTests();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.provider-1']]], 200)]);
    $gateway = new MetaWhatsAppCloudApiGateway;
    $result = $gateway->sendTemplate(new WhatsAppTemplateMessage('+40722123456', 'appointment_confirmation', 'en_US', ['Alex'], [], 'delivery-12'));

    expect($result)->toBe(['message_id' => 'wamid.provider-1', 'status' => 'accepted']);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v26.0/123456789/messages')
        && $request->hasHeader('Authorization', 'Bearer test-access-token')
        && $request['type'] === 'template'
        && $request['template']['name'] === 'easyenglish_appointment_confirmation'
        && $request['biz_opaque_callback_data'] === 'delivery-12');
});

test('Meta Cloud API rejection exposes safe provider error details for diagnostics', function () {
    enableWhatsAppForTests();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => [
            'message' => 'Template name does not exist in the translation.',
            'type' => 'OAuthException',
            'code' => 132001,
            'fbtrace_id' => 'must-not-be-included',
        ],
    ], 400)]);

    try {
        config(['services.whatsapp.templates.contact_verification' => 'english_contact_verification']);
        (new MetaWhatsAppCloudApiGateway)->sendTemplate(new WhatsAppTemplateMessage('+40722123456', 'contact_verification', 'en_US', ['123456'], [], 'contact-verification-test'));
        test()->fail('Expected the Meta API rejection to throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('HTTP 400')
            ->toContain('132001')
            ->toContain('OAuthException')
            ->toContain('Template name does not exist in the translation.')
            ->not->toContain('must-not-be-included');
    }
});

test('WhatsApp provider failures are retried with a redacted error on the same outbox record', function () {
    enableWhatsAppForTests();
    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $delivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
    $delivery->update(['status' => 'queued']);
    $gateway = Mockery::mock(WhatsAppGateway::class);
    $gateway->shouldReceive('sendTemplate')->once()->andThrow(new RuntimeException('Bearer token must not be persisted'));
    app()->instance(WhatsAppGateway::class, $gateway);

    (new SendNotificationDelivery($delivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        $gateway,
    );

    expect($delivery->fresh()->status)->toBe('pending')
        ->and($delivery->fresh()->attempts)->toBe(1)
        ->and($delivery->fresh()->next_attempt_at)->not->toBeNull()
        ->and($delivery->fresh()->last_error)->toBe('WhatsApp delivery failed (RuntimeException).')
        ->and($delivery->fresh()->last_error)->not->toContain('token')
        ->and($delivery->fresh()->last_error)->not->toContain($booking->customer_phone);
});

test('WhatsApp webhook verification challenge requires the configured verify token', function () {
    enableWhatsAppForTests();

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-test-token&hub.challenge=challenge-123')
        ->assertOk()->assertSeeText('challenge-123');
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge-123')->assertForbidden();
});

test('WhatsApp webhook validates raw-body signatures, deduplicates status events, and does not regress read state', function () {
    enableWhatsAppForTests();
    $delivery = NotificationDelivery::query()->create([
        'channel' => 'whatsapp', 'event' => 'appointment_confirmed', 'status' => 'sent', 'attempts' => 1,
        'idempotency_key' => 'whatsapp:test-status-1', 'provider_message_id' => 'wamid.status-1', 'provider_status' => 'accepted',
        'next_attempt_at' => null,
    ]);
    $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['statuses' => [
        ['id' => 'wamid.status-1', 'status' => 'delivered', 'timestamp' => '100'],
        ['id' => 'wamid.status-1', 'status' => 'read', 'timestamp' => '101'],
    ]]]]]]];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $raw, 'meta-test-secret');
    $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid'], $raw)->assertUnauthorized();
    $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $raw)->assertOk();
    $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $raw)->assertOk();

    expect($delivery->fresh()->status)->toBe('read')
        ->and($delivery->fresh()->provider_status)->toBe('read')
        ->and(DB::table('whatsapp_webhook_events')->count())->toBe(2);
});

test('failed provider status is persisted with a redacted provider error code', function () {
    enableWhatsAppForTests();
    $delivery = NotificationDelivery::query()->create([
        'channel' => 'whatsapp', 'event' => 'appointment_confirmed', 'status' => 'sent', 'attempts' => 1,
        'idempotency_key' => 'whatsapp:test-status-failed', 'provider_message_id' => 'wamid.failed-1', 'provider_status' => 'accepted',
        'next_attempt_at' => null,
    ]);

    app(WhatsAppWebhookService::class)->handle(['entry' => [['changes' => [['value' => ['statuses' => [
        ['id' => 'wamid.failed-1', 'status' => 'failed', 'timestamp' => '200', 'errors' => [['code' => 131026, 'title' => 'Recipient cannot be reached']],
        ]]]]]]]]);

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->provider_status)->toBe('failed')
        ->and($delivery->fresh()->provider_error_code)->toBe('131026')
        ->and($delivery->fresh()->last_error)->toContain('131026')
        ->and($delivery->fresh()->last_error)->not->toContain('Recipient cannot be reached');
});

test('STOP reply opts the contact out and skips pending WhatsApp deliveries without affecting email', function () {
    enableWhatsAppForTests();
    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $whatsappDelivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
    $emailDelivery = NotificationDelivery::query()->where('channel', 'email')->firstOrFail();

    $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [
        ['id' => 'wamid.inbound-stop-1', 'from' => '40722123456', 'type' => 'text', 'text' => ['body' => 'STOP']],
    ]]]]]]];
    app(WhatsAppWebhookService::class)->handle($payload);
    app(WhatsAppWebhookService::class)->handle($payload);

    expect(WhatsAppContact::query()->where('phone', '+40722123456')->firstOrFail()->opted_out_at)->not->toBeNull()
        ->and($whatsappDelivery->fresh()->status)->toBe('skipped')
        ->and($emailDelivery->fresh()->status)->toBe('pending')
        ->and(DB::table('whatsapp_webhook_events')->where('event_type', 'opt_out')->count())->toBe(1);
});

test('OpenWA can be selected without initializing Meta and sends a rendered text message through its API', function () {
    config([
        'services.whatsapp.provider' => 'openwa',
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.timeout' => 15,
    ]);
    app()->forgetInstance(WhatsAppGateway::class);
    Http::fake(['openwa.example.com/v1/messages' => Http::response(['providerMessageId' => 'openwa-message-123', 'status' => 'accepted'], 201)]);

    $result = app(WhatsAppGateway::class)->sendTemplate(new WhatsAppTemplateMessage('+40722123456', 'contact_verification', 'en', ['123456'], [['index' => '0', 'parameters' => ['123456']]], 'contact-verification-12345678'));

    expect($result)->toBe(['message_id' => 'openwa-message-123', 'status' => 'accepted']);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://openwa.example.com/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 48))
        && $request->hasHeader('Idempotency-Key', 'contact-verification-12345678')
        && $request['to'] === '+40722123456'
        && str_contains($request['text'], '123456')
        && ! str_contains($request['text'], 'easyenglish_activation'));
});

test('unknown WhatsApp provider fails closed instead of silently choosing Meta', function () {
    config(['services.whatsapp.provider' => 'unknown']);
    app()->forgetInstance(WhatsAppGateway::class);

    expect(fn () => app(WhatsAppGateway::class))->toThrow(InvalidArgumentException::class, 'WHATSAPP_PROVIDER must be either meta or openwa.');
});

test('OpenWA inbound callback is authenticated and STOP opts out using the shared contact record', function () {
    enableWhatsAppForTests();
    config([
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
    ]);
    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $delivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
    $payload = json_encode(['id' => 'wamid-openwa-stop-1', 'from' => '40722123456', 'to' => '40700000000', 'timestamp' => '1794050000', 'text' => 'STOP'], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $payload, str_repeat('c', 48));

    $this->call('POST', '/webhooks/openwa', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_OPENWA_SIGNATURE' => 'sha256=invalid'], $payload)->assertUnauthorized();
    $this->call('POST', '/webhooks/openwa', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_OPENWA_SIGNATURE' => $signature], $payload)->assertOk();

    expect(WhatsAppContact::query()->where('phone', '+40722123456')->firstOrFail()->opted_out_at)->not->toBeNull()
        ->and($delivery->fresh()->status)->toBe('skipped')
        ->and(DB::table('messages')->where('provider_message_id', 'openwa:wamid-openwa-stop-1')->exists())->toBeTrue();
});

test('WhatsApp connection loss quarantines the delivery as ambiguous instead of automatically retrying through another provider', function () {
    enableWhatsAppForTests();
    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $delivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
    $delivery->update(['status' => 'queued']);
    $gateway = Mockery::mock(WhatsAppGateway::class);
    $gateway->shouldReceive('sendTemplate')->once()->andThrow(new ConnectionException('Connection timed out after request submission.'));

    (new SendNotificationDelivery($delivery->id))->handle(app(NotificationMessageFactory::class), app(WhatsAppMessageFactory::class), $gateway);

    expect($delivery->fresh()->status)->toBe('ambiguous')
        ->and($delivery->fresh()->next_attempt_at)->toBeNull()
        ->and($delivery->fresh()->provider)->toBe('meta');
});

test('OpenWA renderer covers every transactional template and validates parameter counts', function () {
    $counts = [
        'contact_verification' => 1, 'purchase_confirmation' => 5, 'intro_booking_confirmation' => 4,
        'appointment_confirmation' => 4, 'appointment_reminder_24h' => 4, 'appointment_reminder_1h' => 4,
        'booking_cancelled' => 3, 'booking_rescheduled' => 4, 'teacher_cancellation' => 3,
        'booking_no_show' => 3, 'refund_succeeded' => 3, 'refund_failed' => 3,
        'teacher_booking_confirmed' => 12, 'teacher_booking_rescheduled' => 11,
        'customer_call_link_ready' => 7, 'teacher_call_link_ready' => 13,
        'teacher_booking_cancelled' => 4, 'bundle_expiry_14d' => 4, 'bundle_expiry_3d' => 4,
    ];
    $renderer = app(OpenWaMessageRenderer::class);
    foreach ($counts as $key => $count) {
        $message = new WhatsAppTemplateMessage('+40722123456', $key, 'en', array_fill(0, $count, 'test-value'), [], 'renderer-test-'.$key.'-key');
        expect($renderer->render($message))->toContain('test-value');
    }
});

test('OpenWA provider configuration is independent of the Meta enabled flag', function () {
    config([
        'services.whatsapp.provider' => 'openwa',
        'services.whatsapp.enabled' => false,
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
    ]);
    $configuration = app(WhatsAppProviderConfiguration::class);

    expect($configuration->provider())->toBe('openwa')
        ->and($configuration->isEnabled())->toBeTrue()
        ->and($configuration->isConfigured())->toBeTrue();
});

test('OpenWA ambiguous delivery can be reconciled as accepted or safely failed', function () {
    config([
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
    ]);
    $delivery = NotificationDelivery::query()->create([
        'channel' => 'whatsapp', 'event' => 'appointment_confirmed', 'status' => 'ambiguous', 'attempts' => 1,
        'idempotency_key' => 'whatsapp:reconcile-test-1', 'provider' => 'openwa', 'next_attempt_at' => null,
    ]);
    Http::fake(['openwa.example.com/v1/messages/*' => Http::sequence()
        ->push(['status' => 'accepted', 'providerMessageId' => 'wamid-openwa-accepted', 'attempts' => 1, 'acceptedAt' => now()->toIso8601String()], 200)
        ->push(['status' => 'failed', 'providerMessageId' => null, 'attempts' => 1, 'acceptedAt' => null], 200)]);

    expect(app(OpenWaMessageReconciliationService::class)->reconcile($delivery))->toBe('sent')
        ->and($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->provider_message_id)->toBe('wamid-openwa-accepted');

    $failed = NotificationDelivery::query()->create([
        'channel' => 'whatsapp', 'event' => 'appointment_confirmed', 'status' => 'ambiguous', 'attempts' => 1,
        'idempotency_key' => 'whatsapp:reconcile-test-2', 'provider' => 'openwa', 'next_attempt_at' => null,
    ]);
    expect(app(OpenWaMessageReconciliationService::class)->reconcile($failed))->toBe('failed')
        ->and($failed->fresh()->status)->toBe('failed');
});

test('the same outbox delivery can fail over between OpenWA and Meta while retaining provider attempts', function (): void {
    enableWhatsAppForTests();
    config([
        'services.whatsapp.provider' => 'openwa',
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
    ]);
    Http::fake([
        'openwa.example.com/v1/messages' => Http::sequence()
            ->push(['error' => 'provider_rejected'], 400)
            ->push(['providerMessageId' => 'openwa-after-failover', 'status' => 'accepted'], 201),
        'graph.facebook.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'meta-after-failover']]], 200)
            ->push(['error' => ['message' => 'rejected']], 400),
    ]);

    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $delivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
    $delivery->update(['status' => 'queued']);

    (new SendNotificationDelivery($delivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        app(OpenWaWhatsAppGateway::class),
    );
    expect($delivery->fresh()->status)->toBe('pending')
        ->and($delivery->fresh()->provider_attempts[0]['provider'])->toBe('openwa')
        ->and($delivery->fresh()->provider_attempts[0]['status'])->toBe('failed');

    config(['services.whatsapp.provider' => 'meta']);
    $delivery->refresh();
    $delivery->update(['status' => 'queued', 'next_attempt_at' => null]);
    (new SendNotificationDelivery($delivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        new MetaWhatsAppCloudApiGateway,
    );
    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->provider)->toBe('meta')
        ->and($delivery->fresh()->provider_attempts)->toHaveCount(2)
        ->and($delivery->fresh()->provider_attempts[1]['status'])->toBe('accepted');

    $secondBooking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($secondBooking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($secondBooking);
    $secondDelivery = NotificationDelivery::query()->where('channel', 'whatsapp')->where('id', '!=', $delivery->id)->firstOrFail();
    $secondDelivery->update(['status' => 'queued']);
    (new SendNotificationDelivery($secondDelivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        new MetaWhatsAppCloudApiGateway,
    );
    expect($secondDelivery->fresh()->status)->toBe('pending')
        ->and($secondDelivery->fresh()->provider_attempts[0]['provider'])->toBe('meta')
        ->and($secondDelivery->fresh()->provider_attempts[0]['status'])->toBe('failed');

    config(['services.whatsapp.provider' => 'openwa']);
    $secondDelivery->refresh();
    $secondDelivery->update(['status' => 'queued', 'next_attempt_at' => null]);
    (new SendNotificationDelivery($secondDelivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        app(OpenWaWhatsAppGateway::class),
    );
    expect($secondDelivery->fresh()->status)->toBe('sent')
        ->and($secondDelivery->fresh()->provider)->toBe('openwa')
        ->and($secondDelivery->fresh()->provider_attempts)->toHaveCount(2)
        ->and($secondDelivery->fresh()->provider_attempts[1]['status'])->toBe('accepted');
});

test('Meta status callback reconciles an ambiguous send using its opaque delivery correlation key', function () {
    $delivery = NotificationDelivery::query()->create([
        'channel' => 'whatsapp', 'event' => 'appointment_confirmed', 'status' => 'ambiguous', 'attempts' => 1,
        'idempotency_key' => 'whatsapp:meta-ambiguous-1', 'provider' => 'meta', 'next_attempt_at' => null,
        'provider_attempts' => [['provider' => 'meta', 'attempt' => 1, 'status' => 'ambiguous', 'started_at' => now()->toIso8601String()]],
    ]);
    app(WhatsAppWebhookService::class)->handle([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'statuses' => [[
                        'id' => 'wamid-meta-late-ack', 'status' => 'delivered', 'timestamp' => '1794050000',
                        'biz_opaque_callback_data' => 'easyenglish-delivery-'.$delivery->id,
                    ]],
                ],
            ]],
        ]],
    ]);

    expect($delivery->fresh()->status)->toBe('delivered')
        ->and($delivery->fresh()->provider_message_id)->toBe('wamid-meta-late-ack')
        ->and($delivery->fresh()->provider_attempts[0]['status'])->toBe('delivered');
});
