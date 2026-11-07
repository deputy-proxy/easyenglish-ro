<?php

use App\Mail\OpenWaSessionDisconnected;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config(['services.openwa.service_token' => str_repeat('s', 48)]);
});

function openWaDisconnectionPayload(array $overrides = []): array
{
    return array_merge([
        'eventId' => '7d0b8d0e-64c2-4cb6-91f0-13a44cb2d8b7',
        'sessionId' => 'easyenglish-openwa-poc',
        'reason' => 'connection_lost',
        'disconnectedAt' => '2026-11-07T12:00:00.000Z',
    ], $overrides);
}

test('sends an immediate disconnection email to every staff account', function () {
    Mail::fake();
    $staff = User::factory()->create(['is_staff' => true, 'email' => 'staff@example.com']);
    User::factory()->create(['is_staff' => false, 'email' => 'customer@example.com']);

    $this->withToken(str_repeat('s', 48))
        ->postJson('/webhooks/openwa/status', openWaDisconnectionPayload())
        ->assertAccepted()
        ->assertJson(['status' => 'sent', 'recipient_count' => 1]);

    Mail::assertSent(OpenWaSessionDisconnected::class, function (OpenWaSessionDisconnected $mail) use ($staff): bool {
        return $mail->hasTo($staff->email)
            && $mail->sessionId === 'easyenglish-openwa-poc'
            && $mail->reason === 'connection_lost';
    });
    expect(DB::table('whatsapp_webhook_events')->where('event_type', 'openwa_session_disconnected')->count())->toBe(1);
});

test('rejects callbacks without the OpenWA service token', function () {
    Mail::fake();
    $this->postJson('/webhooks/openwa/status', openWaDisconnectionPayload())->assertUnauthorized();
    Mail::assertNothingSent();
});

test('does not send duplicate emails for the same disconnect event', function () {
    Mail::fake();
    User::factory()->create(['is_staff' => true]);

    $this->withToken(str_repeat('s', 48))->postJson('/webhooks/openwa/status', openWaDisconnectionPayload())->assertAccepted();
    $this->withToken(str_repeat('s', 48))->postJson('/webhooks/openwa/status', openWaDisconnectionPayload())->assertAccepted()->assertJson(['status' => 'duplicate']);

    Mail::assertSent(OpenWaSessionDisconnected::class, 1);
});

test('releases the idempotency key when email delivery fails so the callback can retry', function () {
    Mail::shouldReceive('to')->once()->andReturn(new class
    {
        public function send($mail): void
        {
            throw new RuntimeException('Resend temporarily unavailable.');
        }
    });
    User::factory()->create(['is_staff' => true]);

    $this->withToken(str_repeat('s', 48))->postJson('/webhooks/openwa/status', openWaDisconnectionPayload())->assertStatus(503);
    expect(DB::table('whatsapp_webhook_events')->where('event_type', 'openwa_session_disconnected')->count())->toBe(0);
});
