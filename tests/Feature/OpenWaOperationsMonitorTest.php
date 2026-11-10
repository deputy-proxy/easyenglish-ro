<?php

use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'services.openwa.enabled' => true,
        'services.openwa.apply_to_paid_sessions' => false,
        'services.openwa.rollout_stage' => 'general',
        'services.openwa.rollout_customer_emails' => [],
        'services.openwa.base_url' => 'https://openwa.example.test',
        'services.openwa.service_token' => str_repeat('x', 40),
        'services.openwa.monitor.queue_backlog_threshold' => 50,
        'services.openwa.monitor.queue_oldest_age_minutes' => 10,
    ]);
    Cache::flush();
});

test('notifies staff once when OpenWA readiness is lost and sends a recovery notification', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    Http::fake(['https://openwa.example.test/health/ready' => Http::sequence()
        ->push(['status' => 'not_ready'], 503)
        ->push(['status' => 'not_ready'], 503)
        ->push(['status' => 'ready'], 200)]);

    $this->artisan('openwa:monitor')->assertSuccessful();
    $this->artisan('openwa:monitor')->assertSuccessful();

    expect(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(1);

    $this->artisan('openwa:monitor')->assertSuccessful();

    expect(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(2);
});

test('alerts staff when the database queue backlog exceeds the configured thresholds', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    config(['services.openwa.enabled' => false, 'services.openwa.monitor.queue_backlog_threshold' => 1]);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $this->artisan('openwa:monitor')->assertSuccessful();

    expect(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(1);
});

test('alerts staff when terminal notification delivery failures occur', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    NotificationDelivery::query()->create([
        'recipient_type' => 'customer',
        'channel' => 'email',
        'event' => 'customer_call_link_ready',
        'status' => 'failed',
        'attempts' => 5,
        'idempotency_key' => 'monitor-test-delivery',
    ]);
    config(['services.openwa.enabled' => false]);

    $this->artisan('openwa:monitor')->assertSuccessful();

    expect(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(1);
});

test('alerts staff when provider errors exceed the configured rolling window threshold', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    config(['services.openwa.monitor.provider_error_threshold' => 3]);
    $metrics = Http::sequence()
        ->push(['created' => 0, 'providerErrors' => 0, 'reconnects' => 0, 'connectionState' => 'connected'])
        ->push(['created' => 0, 'providerErrors' => 1, 'reconnects' => 0, 'connectionState' => 'connected'])
        ->push(['created' => 0, 'providerErrors' => 2, 'reconnects' => 0, 'connectionState' => 'connected'])
        ->push(['created' => 0, 'providerErrors' => 3, 'reconnects' => 0, 'connectionState' => 'connected']);
    Http::fake([
        'https://openwa.example.test/health/ready' => Http::response(['status' => 'ready'], 200),
        'https://openwa.example.test/v1/metrics' => $metrics,
    ]);

    foreach (range(1, 4) as $_) {
        $this->artisan('openwa:monitor')->assertSuccessful();
    }

    expect(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(1);
});

test('alerts staff when OpenWA is active but its messaging capability is not ready', function (): void {
    $staff = User::factory()->create(['is_staff' => true]);
    config([
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.test',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
    ]);
    Http::fake([
        'https://openwa.example.test/health/ready' => Http::response(['status' => 'ready'], 200),
        'https://openwa.example.test/v1/capabilities' => Http::response(['messagingReady' => false], 200),
        'https://openwa.example.test/v1/metrics' => Http::response(['providerErrors' => 0], 200),
    ]);

    $this->artisan('openwa:monitor')->assertSuccessful();

    expect(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(1);
});

test('disabled OpenWA messaging readiness does not alert', function (): void {
    $staff = User::factory()->create(['is_staff' => true]);
    config([
        'services.whatsapp.openwa.enabled' => false,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.test',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
    ]);
    Http::fake([
        'https://openwa.example.test/health/ready' => Http::response(['status' => 'ready'], 200),
        'https://openwa.example.test/v1/capabilities' => Http::response(['messagingReady' => false], 200),
        'https://openwa.example.test/v1/metrics' => Http::response(['providerErrors' => 0], 200),
    ]);

    $this->artisan('openwa:monitor')->assertSuccessful();

    expect(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(0);
});

test('ambiguous WhatsApp deliveries remain an active operations alert', function (): void {
    $staff = User::factory()->create(['is_staff' => true]);
    config(['services.openwa.enabled' => false]);
    NotificationDelivery::query()->create([
        'recipient_type' => 'customer',
        'channel' => 'whatsapp',
        'event' => 'customer_call_link_ready',
        'status' => 'ambiguous',
        'provider' => 'openwa',
        'attempts' => 1,
        'idempotency_key' => 'monitor-ambiguous-delivery',
    ]);

    $this->artisan('openwa:monitor')->assertSuccessful();

    expect(DB::table('notifications')->where('notifiable_id', $staff->id)->count())->toBe(1);
});

test('expired WhatsApp worker leases become ambiguous and retain provider attempt history', function (): void {
    $delivery = NotificationDelivery::query()->create([
        'recipient_type' => 'customer',
        'channel' => 'whatsapp',
        'event' => 'customer_call_link_ready',
        'status' => 'sending',
        'provider' => 'meta',
        'provider_attempts' => [[
            'provider' => 'meta', 'attempt' => 1, 'status' => 'sending',
            'started_at' => now()->subMinutes(30)->toIso8601String(),
        ]],
        'attempts' => 1,
        'idempotency_key' => 'expired-whatsapp-worker-lease',
    ]);
    DB::table('notification_deliveries')->where('id', $delivery->id)->update(['updated_at' => now()->subMinutes(30)]);

    $this->artisan('notifications:dispatch')->assertSuccessful();

    expect($delivery->fresh()->status)->toBe('ambiguous')
        ->and($delivery->fresh()->provider_attempts[0]['status'])->toBe('ambiguous')
        ->and($delivery->fresh()->provider_attempts[0]['reason'])->toBe('worker_lease_expired');
});
