<?php

use App\Contracts\Notifications\WhatsAppNotificationGateway;
use App\Contracts\Notifications\WhatsAppVerificationGateway;
use App\Data\Notifications\WhatsAppTemplateMessage;
use App\Data\Notifications\WhatsAppTextMessage;
use App\Jobs\SendNotificationDelivery;
use App\Models\Booking;
use App\Models\Message;
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
        'services.whatsapp.verification_enabled' => true,
        'services.whatsapp.api_version' => 'v26.0',
        'services.whatsapp.phone_number_id' => '123456789',
        'services.whatsapp.access_token' => 'test-access-token',
        'services.whatsapp.app_secret' => 'meta-test-secret',
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
        'services.whatsapp.verify_token' => 'verify-test-token',
    ]);
    $fake = new FakeWhatsAppGateway;
    app()->instance(WhatsAppNotificationGateway::class, $fake);
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
        app(WhatsAppNotificationGateway::class),
    );

    $delivery->refresh();
    $fake = app(FakeWhatsAppGateway::class);
    expect($delivery->status)->toBe('sent')
        ->and($delivery->provider)->toBe('openwa')
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
        ->assertSee(__('public.whatsapp_service_consent'));

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
    $result = $gateway->sendTemplate(new WhatsAppTemplateMessage('+40722123456', 'contact_verification', 'en', ['123456'], [['index' => '0', 'parameters' => ['123456']]], 'contact-verification-test'));

    expect($result)->toBe(['message_id' => 'wamid.provider-1', 'status' => 'accepted']);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v26.0/123456789/messages')
        && $request->hasHeader('Authorization', 'Bearer test-access-token')
        && $request['type'] === 'template'
        && $request['template']['name'] === 'easyenglish_activation'
        && $request['biz_opaque_callback_data'] === 'contact-verification-test');
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
    $gateway = Mockery::mock(WhatsAppNotificationGateway::class);
    $gateway->shouldReceive('sendTemplate')->once()->andThrow(new RuntimeException('Bearer token must not be persisted'));
    app()->instance(WhatsAppNotificationGateway::class, $gateway);

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
        'idempotency_key' => 'whatsapp:test-status-1', 'provider' => 'meta', 'provider_message_id' => 'wamid.status-1', 'provider_status' => 'accepted',
        'provider_attempts' => [['provider' => 'meta', 'attempt' => 1, 'status' => 'accepted', 'message_id' => 'wamid.status-1']],
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

test('OpenWA LICENSE_REQUIRED rejection is terminal and can be manually retried after licensing', function (): void {
    enableWhatsAppForTests();
    Mail::fake();
    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $delivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
    $delivery->update(['status' => 'queued']);

    Http::fake([
        'openwa.example.com/v1/messages' => Http::response([
            'error' => 'OpenWA could not send the message.',
            'code' => 'LICENSE_REQUIRED',
            'outcome' => 'not_sent',
            'retryable' => false,
        ], 502),
    ]);

    (new SendNotificationDelivery($delivery->id))->handle(
        app(NotificationMessageFactory::class),
        app(WhatsAppMessageFactory::class),
        app(OpenWaWhatsAppGateway::class),
    );

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->provider)->toBe('openwa')
        ->and($delivery->fresh()->provider_error_code)->toBe('LICENSE_REQUIRED')
        ->and($delivery->fresh()->next_attempt_at)->toBeNull()
        ->and($delivery->fresh()->provider_attempts[0]['status'])->toBe('failed')
        ->and($delivery->fresh()->provider_attempts[0]['error_code'])->toBe('LICENSE_REQUIRED');
});

test('Meta status callbacks cannot mutate OpenWA notification deliveries', function (): void {
    enableWhatsAppForTests();
    $delivery = NotificationDelivery::query()->create([
        'channel' => 'whatsapp',
        'event' => 'appointment_confirmed',
        'status' => 'sent',
        'attempts' => 1,
        'idempotency_key' => 'whatsapp:openwa-status-isolation',
        'provider' => 'openwa',
        'provider_message_id' => 'openwa-message-status-1',
        'provider_status' => 'accepted',
        'provider_attempts' => [['provider' => 'openwa', 'attempt' => 1, 'status' => 'accepted', 'message_id' => 'openwa-message-status-1']],
        'next_attempt_at' => null,
    ]);

    app(WhatsAppWebhookService::class)->handle(['entry' => [['changes' => [['value' => ['statuses' => [
        ['id' => 'openwa-message-status-1', 'status' => 'failed', 'timestamp' => '200'],
    ]]]]]]]);

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->provider_status)->toBe('accepted')
        ->and($delivery->fresh()->provider_attempts[0]['status'])->toBe('accepted');
});

test('failed provider status is persisted with a redacted provider error code', function () {
    enableWhatsAppForTests();
    $delivery = NotificationDelivery::query()->create([
        'channel' => 'whatsapp', 'event' => 'appointment_confirmed', 'status' => 'sent', 'attempts' => 1,
        'idempotency_key' => 'whatsapp:test-status-failed', 'provider' => 'meta', 'provider_message_id' => 'wamid.failed-1', 'provider_status' => 'accepted',
        'provider_attempts' => [['provider' => 'meta', 'attempt' => 1, 'status' => 'accepted', 'message_id' => 'wamid.failed-1']],
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
    $teacher = User::factory()->create(['is_teacher' => true, 'phone' => '+40722987654', 'phone_verified_at' => now()]);
    WhatsAppContact::recordConsent($teacher->phone, 'teacher_booking_consent');
    $teacherDelivery = NotificationDelivery::query()->create([
        'booking_id' => $booking->id,
        'recipient_type' => 'teacher',
        'recipient_user_id' => $teacher->id,
        'channel' => 'whatsapp',
        'event' => 'teacher_booking_confirmed',
        'status' => 'pending',
        'attempts' => 0,
        'idempotency_key' => 'whatsapp:teacher-stop-test',
    ]);

    $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [
        ['id' => 'wamid.inbound-stop-1', 'from' => '40722123456', 'type' => 'text', 'text' => ['body' => 'STOP']],
        ['id' => 'wamid.inbound-stop-teacher', 'from' => preg_replace('/\\D+/', '', $teacher->phone), 'type' => 'text', 'text' => ['body' => 'STOP']],
    ]]]]]]];
    app(WhatsAppWebhookService::class)->handle($payload);
    app(WhatsAppWebhookService::class)->handle($payload);

    expect(WhatsAppContact::query()->where('phone', '+40722123456')->firstOrFail()->opted_out_at)->not->toBeNull()
        ->and($whatsappDelivery->fresh()->status)->toBe('skipped')
        ->and($teacherDelivery->fresh()->status)->toBe('skipped')
        ->and(WhatsAppContact::query()->where('phone', $teacher->phone)->firstOrFail()->opted_out_at)->not->toBeNull()
        ->and($emailDelivery->fresh()->status)->toBe('pending')
        ->and(DB::table('whatsapp_webhook_events')->where('event_type', 'opt_out')->count())->toBe(2);
});

test('purpose-specific gateway bindings always route verification to Meta and notifications to OpenWA', function () {
    config(['services.whatsapp.provider' => 'meta']);

    expect(app(WhatsAppVerificationGateway::class))->toBeInstanceOf(MetaWhatsAppCloudApiGateway::class)
        ->and(app(WhatsAppNotificationGateway::class))->toBeInstanceOf(OpenWaWhatsAppGateway::class);

    config(['services.whatsapp.provider' => 'openwa']);
    app()->forgetInstance(WhatsAppVerificationGateway::class);
    app()->forgetInstance(WhatsAppNotificationGateway::class);

    expect(app(WhatsAppVerificationGateway::class))->toBeInstanceOf(MetaWhatsAppCloudApiGateway::class)
        ->and(app(WhatsAppNotificationGateway::class))->toBeInstanceOf(OpenWaWhatsAppGateway::class);
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

test('OpenWA linked-id sender is resolved to a real phone number before persisting or applying opt-out', function () {
    enableWhatsAppForTests();
    config([
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.session_id' => 'easyenglish-openwa-poc',
    ]);
    WhatsAppContact::recordConsent('+40732999325', 'registration');
    WhatsAppContact::recordOptOut('+151616808329283', 'whatsapp_reply');
    Http::fake([
        'openwa.example.com/api/sessions/easyenglish-openwa-poc/contacts/*/phone' => Http::response([
            'contactId' => '151616808329283@lid', 'phone' => '40732999325',
        ], 200),
    ]);

    $result = app(WhatsAppWebhookService::class)->handleOpenWaInbound([
        'id' => 'openwa-lid-inbound-1', 'from' => '40732999325', 'senderId' => '151616808329283@lid', 'to' => '40700000000',
        'timestamp' => '1794050000', 'text' => 'Hello',
    ]);

    expect($result['messages_persisted'])->toBe(1)
        ->and(WhatsAppContact::query()->where('phone', '+40732999325')->value('opt_out_source'))->toBe('whatsapp_reply')
        ->and(WhatsAppContact::query()->where('phone', '+151616808329283')->exists())->toBeFalse()
        ->and(Message::query()->sole()->from_phone)->toBe('+40732999325')
        ->and(Message::query()->sole()->context['openwa_sender_id'])->toBe('151616808329283@lid');

});

test('unresolved OpenWA linked-id is retained as an identifier but never stored as a phone contact or opted out', function () {
    enableWhatsAppForTests();
    config([
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.session_id' => 'easyenglish-openwa-poc',
    ]);
    Http::fake(['openwa.example.com/api/sessions/*/contacts/*/phone' => Http::response(['phone' => null], 200)]);

    $result = app(WhatsAppWebhookService::class)->handleOpenWaInbound([
        'id' => 'openwa-lid-unresolved-1', 'from' => '151616808329283@lid', 'senderId' => '151616808329283@lid', 'text' => 'STOP',
    ]);

    expect($result['messages_persisted'])->toBe(1)
        ->and(Message::query()->sole()->from_phone)->toBe('151616808329283@lid')
        ->and(Message::query()->sole()->context['phone_resolution'])->toBe('unresolved')
        ->and(WhatsAppContact::query()->count())->toBe(0)
        ->and(DB::table('whatsapp_webhook_events')->where('event_type', 'opt_out')->count())->toBe(0);
});

test('WhatsApp connection loss quarantines the delivery as ambiguous instead of automatically retrying through another provider', function () {
    enableWhatsAppForTests();
    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);
    $delivery = NotificationDelivery::query()->where('channel', 'whatsapp')->firstOrFail();
    $delivery->update(['status' => 'queued']);
    $gateway = Mockery::mock(WhatsAppNotificationGateway::class);
    $gateway->shouldReceive('sendTemplate')->once()->andThrow(new ConnectionException('Connection timed out after request submission.'));

    (new SendNotificationDelivery($delivery->id))->handle(app(NotificationMessageFactory::class), app(WhatsAppMessageFactory::class), $gateway);

    expect($delivery->fresh()->status)->toBe('ambiguous')
        ->and($delivery->fresh()->next_attempt_at)->toBeNull()
        ->and($delivery->fresh()->provider)->toBe('openwa');
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

test('provider status reports Meta verification and OpenWA messaging independently', function (): void {
    enableWhatsAppForTests();
    config(['services.whatsapp.openwa.enabled' => false, 'services.openwa.enabled' => false]);

    $this->artisan('whatsapp:provider-status')
        ->expectsOutputToContain('Meta verification: configured')
        ->expectsOutputToContain('OpenWA notifications: disabled')
        ->expectsOutputToContain('OpenWA scheduled call events: disabled')
        ->assertSuccessful();

    config([
        'services.whatsapp.verification_enabled' => false,
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
    ]);
    Http::fake([
        'openwa.example.com/v1/capabilities' => Http::response(['messagingReady' => true], 200),
    ]);

    $this->artisan('whatsapp:provider-status')
        ->expectsOutputToContain('Meta verification: disabled')
        ->expectsOutputToContain('OpenWA notifications: configured')
        ->expectsOutputToContain('OpenWA messaging session: ready')
        ->assertSuccessful();
});

test('Meta verification gateway rejects non-verification templates and all free-form text', function (): void {
    enableWhatsAppForTests();

    expect(fn () => app(MetaWhatsAppCloudApiGateway::class)->sendTemplate(
        new WhatsAppTemplateMessage('+40722123456', 'purchase_confirmation', 'en', ['name'], [], 'meta-scope-test')
    ))->toThrow(RuntimeException::class, 'Meta WhatsApp is reserved for contact-verification OTP templates.');

    expect(fn () => app(MetaWhatsAppCloudApiGateway::class)->sendText(
        new WhatsAppTextMessage('+40722123456', 'Not an OTP.', 'meta-scope-test')
    ))->toThrow(RuntimeException::class, 'Meta WhatsApp is reserved for contact-verification OTP templates.');
});

test('OpenWA notification readiness is independent of Meta verification readiness', function () {
    config([
        'services.whatsapp.verification_enabled' => false,
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
    ]);
    $configuration = app(WhatsAppProviderConfiguration::class);

    expect($configuration->isMetaVerificationConfigured())->toBeFalse()
        ->and($configuration->isOpenWaMessagingOutboundConfigured())->toBeTrue()
        ->and($configuration->isOpenWaMessagingConfigured())->toBeTrue();
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

test('ordinary WhatsApp notifications never fall back to Meta when OpenWA is disabled', function (): void {
    enableWhatsAppForTests();
    config([
        'services.whatsapp.verification_enabled' => true,
        'services.whatsapp.openwa.enabled' => false,
    ]);

    $booking = makeWhatsAppBooking();
    WhatsAppContact::recordConsent($booking->customer_phone, 'booking_form');
    app(NotificationOutboxService::class)->enqueueBookingConfirmation($booking);

    expect(NotificationDelivery::query()->where('channel', 'email')->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('channel', 'whatsapp')->exists())->toBeFalse();
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
