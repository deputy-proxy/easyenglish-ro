<?php

use App\\Models\\Message;
use App\\Services\\Notifications\\WhatsAppWebhookService;
use Illuminate\\Support\\Facades\\DB;

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

