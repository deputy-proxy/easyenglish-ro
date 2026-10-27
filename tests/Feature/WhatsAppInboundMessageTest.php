<?php

use App\Models\Message;
use App\Services\Notifications\WhatsAppWebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config(['services.whatsapp.app_secret' => 'inbound-test-secret']);
});

test('incoming WhatsApp messages are persisted once with sender, text, type, and timestamp', function () {
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['display_phone_number' => '40700000000'],
                    'messages' => [[
                        'id' => 'wamid.inbound-text-1',
                        'from' => '40722123456',
                        'timestamp' => '1793100000',
                        'type' => 'text',
                        'text' => ['body' => 'Hello, I would like to practise English.'],
                    ]],
                ],
            ]],
        ]],
    ];

    app(WhatsAppWebhookService::class)->handle($payload);
    app(WhatsAppWebhookService::class)->handle($payload);

    $message = Message::query()->sole();
    expect($message->provider_message_id)->toBe('wamid.inbound-text-1')
        ->and($message->channel)->toBe('whatsapp')
        ->and($message->direction)->toBe('inbound')
        ->and($message->from_phone)->toBe('+40722123456')
        ->and($message->to_phone)->toBe('+40700000000')
        ->and($message->type)->toBe('text')
        ->and($message->body)->toBe('Hello, I would like to practise English.')
        ->and($message->received_at)->not->toBeNull()
        ->and(DB::table('whatsapp_webhook_events')->where('event_type', 'inbound_message')->count())->toBe(1);
});

test('incoming non-text WhatsApp messages are retained in the inbox', function () {
    app(WhatsAppWebhookService::class)->handle([
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => 'wamid.inbound-image-1',
            'from' => '40722123456',
            'timestamp' => '1793100000',
            'type' => 'image',
            'image' => ['id' => 'media-123', 'mime_type' => 'image/jpeg'],
        ]]]]]]],
    ]);

    expect(Message::query()->sole()->type)->toBe('image')
        ->and(Message::query()->sole()->body)->toBeNull();
});

test('failed inbound persistence rolls back the deduplication event and can be retried', function () {
    $payload = ['entry' => [['changes' => [['value' => ['messages' => [[
        'id' => 'wamid.retry-after-failure', 'from' => '40722123456', 'timestamp' => '1793100000',
        'type' => 'text', 'text' => ['body' => 'Please call me back.'],
    ]]]]]]]];
    $failOnce = true;
    Message::creating(function () use (&$failOnce): void {
        if ($failOnce) {
            $failOnce = false;
            throw new RuntimeException('Simulated insert failure.');
        }
    });

    try {
        app(WhatsAppWebhookService::class)->handle($payload);
        test()->fail('Expected the simulated insert failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated insert failure.');
    }

    expect(DB::table('whatsapp_webhook_events')->where('event_type', 'inbound_message')->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);

    $summary = app(WhatsAppWebhookService::class)->handle($payload);
    expect(Message::query()->count())->toBe(1)
        ->and($summary['messages_persisted'])->toBe(1)
        ->and(DB::table('whatsapp_webhook_events')->where('event_type', 'inbound_message')->count())->toBe(1);
});

test('invalid inbound webhook signatures are logged without exposing payloads', function () {
    Log::spy();
    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => []], JSON_THROW_ON_ERROR);
    $response = $this->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
    ], $payload);

    $response->assertUnauthorized();
    Log::shouldHaveReceived('warning')
        ->with('WhatsApp webhook signature rejected.', Mockery::on(fn (array $context): bool => $context['signature_header_present'] === true
            && $context['app_secret_configured'] === true
            && $context['payload_bytes'] === strlen($payload)
        ));
});

test('valid inbound webhooks log processing counts without message content', function () {
    Log::spy();
    $payload = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => 'wamid.controller-test', 'from' => '40722123456', 'timestamp' => '1793100000',
            'type' => 'text', 'text' => ['body' => 'private test content'],
        ]]]]]]],
    ], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $payload, 'inbound-test-secret');

    $response = $this->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $payload);

    $response->assertOk()->assertSeeText('EVENT_RECEIVED');
    Log::shouldHaveReceived('info')
        ->with('WhatsApp webhook processed.', Mockery::on(fn (array $context): bool => $context['entries'] === 1
            && $context['changes'] === 1
            && $context['messages_received'] === 1
            && $context['messages_persisted'] === 1
            && $context['messages_duplicate'] === 0
            && ! array_key_exists('body', $context)
        ));
});

test('signed malformed inbound payloads are logged and rejected', function () {
    Log::spy();
    $payload = '{"object":"not_whatsapp"}';
    $signature = 'sha256='.hash_hmac('sha256', $payload, 'inbound-test-secret');

    $response = $this->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $payload);

    $response->assertBadRequest();
    Log::shouldHaveReceived('warning')
        ->with('WhatsApp webhook payload rejected.', Mockery::on(fn (array $context): bool => $context['json_valid'] === true
            && $context['object_valid'] === false
            && $context['payload_bytes'] === strlen($payload)
        ));
});
