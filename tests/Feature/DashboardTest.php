<?php

use App\Models\Booking;
use App\Models\BookingCallEvent;
use App\Models\ProgressReport;
use App\Models\SessionFeedback;
use App\Models\User;
use App\Services\Booking\CancellationPolicyService;
use Illuminate\Support\Str;

function makeDashboardCallEvent(User $customer, string $status, array $bookingOverrides = [], array $eventOverrides = []): array
{
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $startsAt = now()->addDay()->startOfHour();
    $booking = Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
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
        'customer_user_id' => $user->id,
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
        ->assertSee(__('public.dashboard_calendar_title'));

    $this->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee(__('public.progress_metric_vocabulary'))
        ->assertSee(__('public.dashboard_potential'))
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
            'customer_user_id' => $user->id,
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
        ->assertSee(__('public.dashboard_progress_chart_title'))
        ->assertSee(trans_choice('public.dashboard_progress_assessment_count', 2, ['count' => 2]))
        ->assertSee(__('public.dashboard_session_number', ['number' => $firstBooking->id]))
        ->assertSee(__('public.dashboard_session_number', ['number' => $secondBooking->id]))
        ->assertSee(__('public.dashboard_progress_strongest'))
        ->assertSee('+20 '.__('public.dashboard_progress_points'))
        ->assertSee(__('public.dashboard_progress_reports_title'))
        ->assertSee('Practise explaining decisions in English.')
        ->assertSee('20', false)
        ->assertSee('40', false)
        ->assertSee(__('public.dashboard_progress_partial_note', ['count' => 1]));
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

test('explicit customer ownership preserves dashboard history after an email change', function () {
    $user = User::factory()->create(['email' => 'before-change@example.com']);
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $user->id,
        'customer_name' => $user->name,
        'customer_email' => $user->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Persistent ownership history',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'completed',
        'locale' => 'en',
    ]);

    $user->forceFill(['email' => 'after-change@example.com', 'email_verified_at' => now()])->save();

    $this->actingAs($user)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'sessions', 'session_group' => 'history']))
        ->assertOk()
        ->assertViewHas('sessionBookings', fn ($bookings): bool => $bookings->contains('id', $booking->id));

    expect($booking->fresh()->customer_user_id)->toBe($user->id)
        ->and($booking->fresh()->customer_email)->toBe('before-change@example.com');
});

test('an explicitly owned booking is never exposed to another account through matching snapshots', function () {
    $owner = User::factory()->create(['email' => 'record-owner@example.com']);
    $other = User::factory()->create([
        'email' => 'snapshot-match@example.com',
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
    ]);

    Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $owner->id,
        'customer_name' => $other->name,
        'customer_email' => $other->email,
        'customer_phone' => $other->phone,
        'english_level' => 'B1',
        'session_goal' => 'Must remain private to owner',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'locale' => 'en',
    ]);

    $this->actingAs($other)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('bookings', fn ($bookings): bool => $bookings->doesntContain('id', Booking::query()->where('session_goal', 'Must remain private to owner')->value('id')));
});

function makeCustomerSession(User $customer, array $overrides = []): Booking
{
    $startsAt = $overrides['starts_at'] ?? now()->addHours(18);

    return Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practise workplace conversations',
        'notes' => 'Prepare a short project update.',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
        'duration_minutes' => 30,
        'status' => 'confirmed',
        'locale' => 'en',
    ], $overrides));
}

test('my sessions separates upcoming, history and cancelled bookings with accurate details and signed actions', function () {
    $customer = User::factory()->create(['email' => 'sessions-customer@example.com']);
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_active' => true,
        'name' => 'Alex Teacher',
        'teacher_bio' => 'Business English and interview preparation.',
    ]);
    $upcoming = makeCustomerSession($customer, [
        'teacher_id' => $teacher->id,
        'starts_at' => now()->addHours(18),
        'duration_minutes' => 30,
        'ends_at' => now()->addHours(18)->addMinutes(30),
    ]);
    $pending = makeCustomerSession($customer, [
        'status' => 'pending',
        'session_goal' => 'Awaiting confirmation',
    ]);
    $past = makeCustomerSession($customer, [
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addMinutes(30),
        'status' => 'completed',
        'session_goal' => 'Past completed session',
    ]);
    $cancelled = makeCustomerSession($customer, [
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(30),
        'status' => 'cancelled',
        'session_goal' => 'Cancelled session',
    ]);
    $expired = makeCustomerSession($customer, [
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(3)->addMinutes(30),
        'status' => 'expired',
        'session_goal' => 'Expired session',
    ]);
    $noShow = makeCustomerSession($customer, [
        'starts_at' => now()->subDays(4),
        'ends_at' => now()->subDays(4)->addMinutes(30),
        'status' => 'no_show',
        'session_goal' => 'Missed session',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'sessions']))
        ->assertOk()
        ->assertSee(__('public.dashboard_sessions'))
        ->assertSee('Practise workplace conversations')
        ->assertSee('Alex Teacher')
        ->assertSee('Business English and interview preparation.')
        ->assertSee('30 min')
        ->assertSee('Prepare a short project update.')
        ->assertSee(__('public.booking_status_confirmed'))
        ->assertSee('Awaiting confirmation')
        ->assertSee(__('public.booking_status_pending'))
        ->assertDontSee('Past completed session')
        ->assertDontSee('Cancelled session')
        ->assertViewHas('sessionBookings', fn ($bookings): bool => $bookings->contains('id', $upcoming->id));

    $this->get(route('dashboard', ['view' => 'sessions', 'session_group' => 'history']))
        ->assertOk()
        ->assertSee('Past completed session')
        ->assertDontSee('Cancelled session')
        ->assertViewHas('sessionBookings', fn ($bookings): bool => $bookings->contains('id', $past->id));

    $this->get(route('dashboard', ['view' => 'sessions', 'session_group' => 'cancelled']))
        ->assertOk()
        ->assertSee('Cancelled session')
        ->assertSee('Expired session')
        ->assertSee('Missed session')
        ->assertDontSee('Past completed session')
        ->assertViewHas('sessionBookings', fn ($bookings): bool => $bookings->contains('id', $cancelled->id)
            && $bookings->contains('id', $expired->id)
            && $bookings->contains('id', $noShow->id));

    $actions = app(CancellationPolicyService::class)->customerActions($upcoming);
    expect($actions['can_cancel'])->toBeTrue()
        ->and($actions['can_reschedule'])->toBeTrue();
});

test('my sessions uses bounded pagination and never returns another customer booking', function () {
    $customer = User::factory()->create(['email' => 'sessions-pagination@example.com']);
    $other = User::factory()->create(['email' => 'other-sessions@example.com']);

    $owned = collect(range(1, 12))->map(fn (int $number): Booking => makeCustomerSession($customer, [
        'session_goal' => 'Owned session '.$number,
        'starts_at' => now()->addDays($number),
        'ends_at' => now()->addDays($number)->addMinutes(30),
    ]));
    makeCustomerSession($other, [
        'session_goal' => 'Another customer private session',
        'starts_at' => now()->addDays(20),
        'ends_at' => now()->addDays(20)->addMinutes(30),
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'sessions']))
        ->assertOk()
        ->assertViewHas('sessionBookings', function ($bookings) use ($owned): bool {
            return $bookings->total() === 12
                && $bookings->count() === 10
                && $bookings->getCollection()->pluck('id')->diff($owned->pluck('id'))->isEmpty();
        })
        ->assertDontSee('Another customer private session');

    $this->get(route('dashboard', ['view' => 'sessions', 'sessions_page' => 2]))
        ->assertOk()
        ->assertSee('Owned session 12')
        ->assertDontSee('Another customer private session');
});

test('my sessions links reuse signed booking management and reschedule routes only when policy permits', function () {
    $customer = User::factory()->create(['email' => 'sessions-actions@example.com']);
    $eligible = makeCustomerSession($customer, [
        'starts_at' => now()->addHours(18),
        'ends_at' => now()->addHours(18)->addMinutes(30),
    ]);
    $ineligible = makeCustomerSession($customer, [
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(2)->addMinutes(30),
        'reschedule_count' => 1,
        'session_goal' => 'No free reschedule',
    ]);

    $response = $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'sessions', 'session_group' => 'all']))
        ->assertOk()
        ->assertSee(__('public.dashboard_session_manage'))
        ->assertSee(__('public.booking_reschedule_title'));

    $response->assertViewHas('sessionActions', function (array $actions) use ($eligible, $ineligible): bool {
        return $actions[$eligible->id]['can_cancel'] === true
            && $actions[$eligible->id]['can_reschedule'] === true
            && $actions[$ineligible->id]['can_cancel'] === true
            && $actions[$ineligible->id]['can_reschedule'] === false
            && is_string($actions[$eligible->id]['reschedule_url'])
            && $actions[$ineligible->id]['reschedule_url'] === null;
    });

    $actions = $response->viewData('sessionActions');
    $this->get($actions[$eligible->id]['manage_url'])
        ->assertOk()
        ->assertSee(__('public.booking_cancel_title'));
    $this->get($actions[$eligible->id]['reschedule_url'])
        ->assertOk()
        ->assertSee(__('public.booking_reschedule_title'));
});

test('my sessions exposes only the current authorized OpenWA join action for future confirmed bookings', function () {
    $customer = User::factory()->create(['email' => 'session-call-link@example.com']);
    $ready = makeDashboardCallEvent($customer, 'scheduled');

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'sessions']))
        ->assertOk()
        ->assertSee(__('public.dashboard_call_link_ready'))
        ->assertSee('href="'.route('booking.call-join', $ready['booking']->public_reference).'"', false)
        ->assertDontSee('https://call.whatsapp.com/dashboard-link');
});

test('learner profile prefers the latest published teacher-assessed level and never exposes drafts or another customer reports', function () {
    $customer = User::factory()->create([
        'english_level' => 'B1',
        'session_goal' => 'Speak confidently in meetings',
        'session_notes' => 'Prefer short role-play exercises.',
    ]);
    $otherCustomer = User::factory()->create(['english_level' => 'A2']);

    $makeReportBooking = function (User $owner, int $daysAgo): Booking {
        return Booking::query()->create([
            'public_reference' => (string) Str::uuid(),
            'customer_user_id' => $owner->id,
            'customer_name' => $owner->name,
            'customer_email' => $owner->email,
            'customer_phone' => '+40722123456',
            'english_level' => $owner->english_level ?? 'A2',
            'session_goal' => 'Assessment session',
            'accepted_policy_version' => 'test-policy',
            'starts_at' => now()->subDays($daysAgo),
            'ends_at' => now()->subDays($daysAgo)->addMinutes(30),
            'duration_minutes' => 30,
            'status' => 'completed',
            'locale' => 'en',
        ]);
    };

    $olderBooking = $makeReportBooking($customer, 20);
    $olderReport = ProgressReport::query()->create([
        'booking_id' => $olderBooking->id,
        'title' => 'Earlier shared assessment',
        'overall_level' => 'B2',
        'summary' => 'An earlier published assessment.',
        'shared_at' => now()->subDays(18),
    ]);

    $latestBooking = $makeReportBooking($customer, 3);
    $latestReport = ProgressReport::query()->create([
        'booking_id' => $latestBooking->id,
        'title' => 'Latest published assessment',
        'overall_level' => 'C1',
        'summary' => 'A teacher assessed the learner at C1.',
        'shared_at' => now()->subDays(1),
    ]);

    $draftBooking = $makeReportBooking($customer, 2);
    $draftReport = ProgressReport::query()->create([
        'booking_id' => $draftBooking->id,
        'title' => 'Private draft assessment',
        'overall_level' => 'C2',
        'summary' => 'This report has not been shared.',
        'shared_at' => null,
    ]);

    $otherBooking = $makeReportBooking($otherCustomer, 1);
    $otherReport = ProgressReport::query()->create([
        'booking_id' => $otherBooking->id,
        'title' => 'Another learner assessment',
        'overall_level' => 'A1',
        'summary' => 'This belongs to another learner.',
        'shared_at' => now(),
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'profile']))
        ->assertOk()
        ->assertSee(__('public.profile_learning_title'))
        ->assertSee('C1')
        ->assertSee(__('public.profile_level_assessed'))
        ->assertSee('Speak confidently in meetings')
        ->assertSee('Prefer short role-play exercises.')
        ->assertSee('Latest published assessment')
        ->assertViewHas('learnerProfileLevel', 'C1')
        ->assertViewHas('learnerProfileLevelSource', 'assessed')
        ->assertViewHas('latestAssessedProgressReport', fn ($report): bool => $report?->is($latestReport))
        ->assertDontSee('Private draft assessment')
        ->assertDontSee('Another learner assessment')
        ->assertDontSee('A teacher assessed the learner at C1.');

    expect($olderReport->exists)->toBeTrue()
        ->and($draftReport->exists)->toBeTrue()
        ->and($otherReport->exists)->toBeTrue();
});

test('learner profile labels the account level as self-reported until a shared assessed level exists', function () {
    $customer = User::factory()->create([
        'english_level' => 'B1',
        'session_goal' => 'Travel with confidence',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'profile']))
        ->assertOk()
        ->assertSee('B1')
        ->assertSee(__('public.profile_level_self_reported'))
        ->assertViewHas('learnerProfileLevel', 'B1')
        ->assertViewHas('learnerProfileLevelSource', 'self_reported')
        ->assertViewHas('latestAssessedProgressReport', null);
});

test('learner profile has a useful empty state when no level or goals have been supplied', function () {
    $customer = User::factory()->create([
        'english_level' => null,
        'session_goal' => null,
        'session_notes' => null,
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'profile']))
        ->assertOk()
        ->assertSee(__('public.profile_level_not_set'))
        ->assertSee(__('public.profile_no_goal'))
        ->assertSee(__('public.profile_no_preferences'))
        ->assertViewHas('learnerProfileLevel', null)
        ->assertViewHas('learnerProfileLevelSource', 'unset');
});

test('learner profile route is customer scoped and accessible from dashboard navigation', function () {
    $customer = User::factory()->create();
    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('dashboard', ['view' => 'profile']), false)
        ->assertSee(__('public.dashboard_learning'));
});

test('learner profile uses Romanian translations and identifies the level source', function () {
    $customer = User::factory()->create([
        'english_level' => 'A2',
        'session_goal' => 'Conversații în călătorii',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'ro'])
        ->get(route('dashboard', ['view' => 'profile']))
        ->assertOk()
        ->assertSee('Profilul tău de învățare')
        ->assertSee('Nivelul actual de engleză')
        ->assertSee('Autoevaluat')
        ->assertSee('Obiectivul principal de învățare');
});
