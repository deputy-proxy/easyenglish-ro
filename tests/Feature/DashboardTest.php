<?php

use App\Models\Booking;
use App\Models\BookingCallEvent;
use App\Models\ProgressReport;
use App\Models\SessionFeedback;
use App\Models\User;
use Illuminate\Support\Str;

function makeDashboardCallEvent(User $customer, string $status, array $bookingOverrides = [], array $eventOverrides = []): array
{
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $startsAt = now()->addDay()->startOfHour();
    $booking = Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'teacher_id' => $teacher->id,
        'teacher_assigned_at' => now(),
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'locale' => 'en',
    ], $bookingOverrides));
    $event = BookingCallEvent::query()->create(array_merge([
        'booking_id' => $booking->id,
        'teacher_id' => $teacher->id,
        'provider' => 'openwa',
        'revision' => 1,
        'idempotency_key' => 'booking_call_event_'.str_repeat('a', 64),
        'provider_event_id' => $status === 'scheduled' ? 'fake-event-dashboard-123456' : null,
        'call_type' => 'video',
        'scheduled_start_at' => $booking->starts_at,
        'scheduled_end_at' => $booking->ends_at,
        'status' => $status,
        'attempts' => 1,
        'join_url' => $status === 'scheduled' ? 'https://call.whatsapp.com/dashboard-link' : null,
    ], $eventOverrides));

    return compact('booking', 'event', 'teacher');
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('calendar and progress dashboard views show the learner’s own sessions and feedback', function () {
    $user = User::factory()->create(['email' => 'dashboard-learner@example.com']);
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_name' => $user->name,
        'customer_email' => $user->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'locale' => 'en',
    ]);
    SessionFeedback::query()->create([
        'booking_id' => $booking->id,
        'metric_key' => 'vocabulary',
        'baseline_score' => 30,
        'current_score' => 55,
        'potential_score' => 85,
        'feedback' => 'Practise everyday vocabulary in short conversations.',
    ]);

    $this->actingAs($user)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'calendar']))
        ->assertOk()
        ->assertSee('Choose a session time');

    $this->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee('Vocabulary')
        ->assertSee('Potential development')
        ->assertSee('85')
        ->assertSee('Practise everyday vocabulary')
        ->assertDontSee(__('public.dashboard_current'));
});

test('progress dashboard plots complete session assessments as separate radar chart series', function () {
    $user = User::factory()->create(['email' => 'radar-learner@example.com']);
    $metrics = ['vocabulary', 'pronunciation', 'fluency', 'grammar', 'confidence'];
    $baseline = [20, 25, 30, 35, 40];
    $firstScores = [35, 40, 45, 50, 55];
    $secondScores = [40, 45, 50, 55, 60];

    $makeBooking = function (int $daysAgo) use ($user): Booking {
        return Booking::query()->create([
            'public_reference' => (string) Str::uuid(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'customer_phone' => '+40722123456',
            'english_level' => 'B1',
            'session_goal' => 'Work conversations',
            'accepted_policy_version' => 'test-policy',
            'starts_at' => now()->subDays($daysAgo),
            'ends_at' => now()->subDays($daysAgo)->addMinutes(15),
            'duration_minutes' => 15,
            'status' => 'completed',
            'locale' => 'en',
        ]);
    };

    $firstBooking = $makeBooking(10);
    foreach ($metrics as $index => $metric) {
        SessionFeedback::query()->create([
            'booking_id' => $firstBooking->id,
            'metric_key' => $metric,
            'baseline_score' => $baseline[$index],
            'current_score' => $firstScores[$index],
            'potential_score' => 90,
        ]);
    }

    $secondBooking = $makeBooking(3);
    foreach ($metrics as $index => $metric) {
        SessionFeedback::query()->create([
            'booking_id' => $secondBooking->id,
            'metric_key' => $metric,
            'baseline_score' => $baseline[$index],
            'current_score' => $secondScores[$index],
            'potential_score' => 95,
        ]);
    }

    ProgressReport::query()->create([
        'booking_id' => $secondBooking->id,
        'title' => 'Your latest progress report',
        'summary' => 'You are more comfortable maintaining a work conversation.',
        'strengths' => 'Clearer vocabulary choices.',
        'areas_to_improve' => 'Use more varied sentence structures.',
        'recommendations' => 'Practise explaining decisions in English.',
        'shared_at' => now(),
        'created_by' => $user->id,
    ]);

    $partialBooking = $makeBooking(1);
    foreach (array_slice($metrics, 0, 2) as $index => $metric) {
        SessionFeedback::query()->create([
            'booking_id' => $partialBooking->id,
            'metric_key' => $metric,
            'current_score' => 60 + $index,
        ]);
    }

    $this->actingAs($user)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee('Your development across five dimensions')
        ->assertSee('2 complete session assessments')
        ->assertSee('Session #'.$firstBooking->id)
        ->assertSee('Session #'.$secondBooking->id)
        ->assertSee('Strongest measured improvement')
        ->assertSee('+20 points')
        ->assertSee('Your latest progress report')
        ->assertSee('Practise explaining decisions in English.')
        ->assertSee('20', false)
        ->assertSee('40', false)
        ->assertSee('1 session assessment(s) are incomplete', false);
});

test('dashboard shows pending and failed call-link states without implying the booking failed', function () {
    $customer = User::factory()->create(['email' => 'call-status@example.com']);
    makeDashboardCallEvent($customer, 'pending', ['public_reference' => (string) Str::uuid()]);
    makeDashboardCallEvent($customer, 'failed', ['public_reference' => (string) Str::uuid()], ['idempotency_key' => 'booking_call_event_'.str_repeat('b', 64)]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_call_link_pending'))
        ->assertSee(__('public.dashboard_call_link_failed'))
        ->assertSee(__('public.booking_status_confirmed'))
        ->assertDontSee('https://call.whatsapp.com/dashboard-link');
});

test('dashboard does not describe a pending booking as confirmed', function () {
    $customer = User::factory()->create(['email' => 'booking-pending@example.com']);
    makeDashboardCallEvent($customer, 'pending', [
        'public_reference' => (string) Str::uuid(),
        'status' => 'pending',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_booking_pending'))
        ->assertDontSee(__('public.dashboard_call_link_pending'));
});

test('dashboard exposes only the current scheduled call link for the authenticated customer', function () {
    $customer = User::factory()->create(['email' => 'call-ready@example.com']);
    $ready = makeDashboardCallEvent($customer, 'scheduled');
    $stale = makeDashboardCallEvent($customer, 'scheduled', [
        'public_reference' => (string) Str::uuid(),
        'reschedule_count' => 1,
    ], [
        'idempotency_key' => 'booking_call_event_'.str_repeat('c', 64),
        'provider_event_id' => 'fake-event-stale-123456',
        'join_url' => 'https://call.whatsapp.com/stale-link',
        'revision' => 1,
    ]);
    $otherCustomer = User::factory()->create(['email' => 'another-customer@example.com']);
    makeDashboardCallEvent($otherCustomer, 'scheduled', ['public_reference' => (string) Str::uuid()], [
        'idempotency_key' => 'booking_call_event_'.str_repeat('d', 64),
        'provider_event_id' => 'fake-event-other-123456',
        'join_url' => 'https://call.whatsapp.com/another-customer-link',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_call_link_ready'))
        ->assertSee('href="'.route('booking.call-join', $ready['booking']->public_reference).'"', false)
        ->assertSee(__('public.dashboard_call_link_not_started'))
        ->assertDontSee('https://call.whatsapp.com/dashboard-link')
        ->assertDontSee('https://call.whatsapp.com/stale-link')
        ->assertDontSee('https://call.whatsapp.com/another-customer-link')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect($ready['booking']->currentCallEvent()?->id)->toBe($ready['event']->id)
        ->and($stale['booking']->currentCallEvent())->toBeNull();
});
