<?php

use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\Payment;
use App\Models\ProgressReport;
use App\Models\SessionFeedback;
use App\Models\User;
use App\Services\Progress\ProgressChartData;
use Illuminate\Support\Str;

function createOverviewEntitlement(User $customer, array $overrides = []): BundleEntitlement
{
    $definition = BundleDefinition::query()->firstOrFail();

    return BundleEntitlement::query()->create(array_merge([
        'customer_user_id' => $customer->id,
        'bundle_definition_id' => $definition->id,
        'bundle_name_snapshot' => 'Overview '.$definition->name,
        'bundle_description_snapshot' => 'Overview test bundle',
        'included_free_credits_snapshot' => 1,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'locale' => 'en',
        'status' => 'active',
        'paid_amount_ron' => 390,
        'currency' => 'RON',
        'paid_credits' => 10,
        'free_credits_granted' => 1,
        'credits_remaining' => 10,
        'free_credits_remaining' => 1,
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(30),
    ], $overrides));
}

function createOverviewBooking(User $customer, array $overrides = []): Booking
{
    return Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Prepare for professional conversations',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addMinutes(30),
        'duration_minutes' => 30,
        'status' => 'confirmed',
        'locale' => 'en',
    ], $overrides));
}

test('dashboard navigation groups customer areas and keeps calendar as a prominent action', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_overview'))
        ->assertSee(__('public.dashboard_sessions'))
        ->assertSee(__('public.dashboard_learning'))
        ->assertSee(__('public.dashboard_purchases'))
        ->assertSee(__('public.dashboard_account'))
        ->assertSee(__('public.dashboard_book_session'))
        ->assertDontSee(__('public.dashboard_teacher_availability'))
        ->assertSee(route('dashboard', ['view' => 'calendar']), false)
        ->assertSee(route('dashboard', ['view' => 'account']), false)
        ->assertDontSee(__('public.dashboard_calendar'));

    $this->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee(__('public.dashboard_learning_navigation'))
        ->assertSee(__('public.dashboard_learning_profile_tab'))
        ->assertSee(__('public.dashboard_learning_progress_tab'))
        ->assertSee('aria-current="page"', false);

    $this->get(route('dashboard', ['view' => 'calendar']))
        ->assertOk()
        ->assertSee(__('public.dashboard_back_to_overview'));
});

test('overview shows the actual next-session duration, assigned teacher, status, and signed details link', function () {
    $customer = User::factory()->create();
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_active' => true,
    ]);
    $booking = createOverviewBooking($customer, [
        'teacher_id' => $teacher->id,
        'duration_minutes' => 45,
        'status' => 'pending',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(45),
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_next_session'))
        ->assertSee('45 '.__('public.booking_duration_minutes'))
        ->assertSee($teacher->name)
        ->assertSee(__('public.booking_status_pending'))
        ->assertSee(__('public.dashboard_next_session_details'))
        ->assertViewHas('nextSession', fn ($session): bool => $session?->is($booking))
        ->assertViewHas('nextSessionUrl', fn (?string $url): bool => is_string($url)
            && str_contains($url, $booking->public_reference));
});

test('overview totals only active unexpired usable bundle credits and shows the nearest expiry', function () {
    $customer = User::factory()->create();
    createOverviewEntitlement($customer, [
        'bundle_name_snapshot' => 'Third active bundle',
        'credits_remaining' => 5,
        'free_credits_remaining' => 0,
        'expires_at' => now()->addDays(20),
    ]);
    $expiring = createOverviewEntitlement($customer, [
        'bundle_name_snapshot' => 'Soon expiring bundle',
        'credits_remaining' => 2,
        'free_credits_remaining' => 1,
        'expires_at' => now()->addDays(4),
    ]);
    $nonExpiring = createOverviewEntitlement($customer, [
        'bundle_name_snapshot' => 'No expiry bundle',
        'credits_remaining' => 1,
        'free_credits_remaining' => 0,
        'expires_at' => null,
    ]);
    createOverviewEntitlement($customer, [
        'bundle_name_snapshot' => 'Expired bundle',
        'credits_remaining' => 5,
        'free_credits_remaining' => 0,
        'expires_at' => now()->subDay(),
    ]);
    createOverviewEntitlement($customer, [
        'bundle_name_snapshot' => 'Pending bundle',
        'status' => 'pending_payment',
        'credits_remaining' => 10,
        'free_credits_remaining' => 0,
    ]);
    createOverviewEntitlement($customer, [
        'bundle_name_snapshot' => 'Exhausted bundle',
        'credits_remaining' => 0,
        'free_credits_remaining' => 0,
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('9')
        ->assertSee('+ 1 more bundle')
        ->assertSee('Soon expiring bundle')
        ->assertSee('No expiry bundle')
        ->assertSee(__('public.dashboard_nearest_expiry'))
        ->assertViewHas('remainingCredits', 9)
        ->assertViewHas('activeBundleCount', 3)
        ->assertViewHas('nearestExpiringEntitlement', fn ($entitlement): bool => $entitlement?->is($expiring))
        ->assertDontSee('Third active bundle')
        ->assertDontSee('Expired bundle')
        ->assertDontSee('Pending bundle')
        ->assertDontSee('Exhausted bundle');

    expect($nonExpiring->expires_at)->toBeNull();
});

test('overview shows assessed level source and a measured improvement only when complete baseline data exists', function () {
    $customer = User::factory()->create(['english_level' => 'B1']);
    $booking = createOverviewBooking($customer, [
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addMinutes(30),
        'status' => 'completed',
    ]);
    ProgressReport::query()->create([
        'booking_id' => $booking->id,
        'title' => 'Published speaking assessment',
        'overall_level' => 'C1',
        'summary' => 'A published assessment.',
        'shared_at' => now()->subDay(),
    ]);

    foreach (ProgressChartData::METRIC_KEYS as $index => $metric) {
        SessionFeedback::query()->create([
            'booking_id' => $booking->id,
            'metric_key' => $metric,
            'baseline_score' => 20 + $index,
            'current_score' => 35 + $index,
        ]);
    }

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('C1')
        ->assertSee(__('public.profile_level_assessed'))
        ->assertSee(__('public.dashboard_progress_highlight_title'))
        ->assertSee('+15 '.__('public.dashboard_progress_points'))
        ->assertViewHas('learnerProfileLevel', 'C1')
        ->assertViewHas('learnerProfileLevelSource', 'assessed')
        ->assertViewHas('progressHighlight', fn (?array $highlight): bool => $highlight !== null
            && $highlight['points'] === 15);

    $customerWithoutBaseline = User::factory()->create(['english_level' => 'A2']);
    $incompleteBaselineBooking = createOverviewBooking($customerWithoutBaseline, [
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(30),
        'status' => 'completed',
    ]);

    foreach (ProgressChartData::METRIC_KEYS as $index => $metric) {
        SessionFeedback::query()->create([
            'booking_id' => $incompleteBaselineBooking->id,
            'metric_key' => $metric,
            'baseline_score' => $index === 4 ? null : 20 + $index,
            'current_score' => 35 + $index,
        ]);
    }

    $this->actingAs($customerWithoutBaseline)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(__('public.dashboard_progress_highlight_title'))
        ->assertViewHas('progressHighlight', null)
        ->assertViewHas('learnerProfileLevelSource', 'self_reported');
});

test('recent activity is built from distinct owned booking, confirmed payment, and published report events in chronological order', function () {
    $customer = User::factory()->create();
    $entitlement = createOverviewEntitlement($customer);
    $booking = createOverviewBooking($customer, [
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addMinutes(30),
    ]);
    $booking->forceFill([
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ])->saveQuietly();

    $payment = Payment::query()->create([
        'bundle_entitlement_id' => $entitlement->id,
        'amount_ron' => 390,
        'currency' => 'RON',
        'status' => 'succeeded',
        'confirmed_at' => now()->subDays(2),
    ]);
    Payment::query()->create([
        'bundle_entitlement_id' => $entitlement->id,
        'amount_ron' => 390,
        'currency' => 'RON',
        'status' => 'pending',
        'confirmed_at' => null,
    ]);

    $report = ProgressReport::query()->create([
        'booking_id' => $booking->id,
        'title' => 'Recent published report',
        'summary' => 'Report summary.',
        'shared_at' => now()->subDay(),
    ]);
    ProgressReport::query()->create([
        'booking_id' => $booking->id,
        'title' => 'Private draft report',
        'summary' => 'Draft summary.',
        'shared_at' => null,
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_recent_activity'))
        ->assertSee(__('public.dashboard_activity_session'))
        ->assertSee(__('public.dashboard_activity_payment'))
        ->assertSee(__('public.dashboard_activity_report'))
        ->assertSee('Recent published report')
        ->assertDontSee('Private draft report')
        ->assertViewHas('recentActivity', function ($events) use ($booking, $payment, $report): bool {
            return $events->count() === 3
                && $events->pluck('key')->all() === [
                    'report:'.$report->id,
                    'payment:'.$payment->id,
                    'booking:'.$booking->id,
                ]
                && $events->pluck('key')->unique()->count() === 3
                && str_contains($events[2]['url'], $booking->public_reference)
                && $events[1]['url'] === route('dashboard', ['view' => 'purchases'])
                && $events[0]['url'] === route('dashboard', ['view' => 'progress', 'report' => $report->id]);
        });
});

test('overview shows useful empty states and primary actions for a new learner', function () {
    $customer = User::factory()->create([
        'english_level' => null,
        'session_goal' => null,
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_no_next_session'))
        ->assertSee(__('public.dashboard_no_bundles'))
        ->assertSee(__('public.dashboard_no_current_level'))
        ->assertSee(__('public.dashboard_activity_empty'))
        ->assertSee(__('public.dashboard_book_session'))
        ->assertSee(__('public.dashboard_sessions'))
        ->assertSee(__('public.dashboard_progress'))
        ->assertSee(__('public.dashboard_purchases'))
        ->assertViewHas('remainingCredits', 0)
        ->assertViewHas('nextSession', null)
        ->assertViewHas('recentActivity', fn ($events): bool => $events->isEmpty())
        ->assertViewHas('progressHighlight', null);
});

test('teacher and staff dashboards cannot see customer records they do not own', function () {
    $customer = User::factory()->create();
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_active' => true,
    ]);
    $staff = User::factory()->create(['is_staff' => true]);
    $entitlement = createOverviewEntitlement($customer, ['bundle_name_snapshot' => 'Customer private bundle']);
    $booking = createOverviewBooking($customer, [
        'teacher_id' => $teacher->id,
        'session_goal' => 'Customer private learning goal',
    ]);
    ProgressReport::query()->create([
        'booking_id' => $booking->id,
        'title' => 'Customer private report',
        'summary' => 'Customer private report summary.',
        'shared_at' => now(),
    ]);

    $this->actingAs($staff)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_no_next_session'))
        ->assertSee(__('public.dashboard_no_bundles'))
        ->assertDontSee('Customer private learning goal')
        ->assertDontSee('Customer private bundle')
        ->assertDontSee('Customer private report')
        ->assertViewHas('remainingCredits', 0)
        ->assertViewHas('recentActivity', fn ($events): bool => $events->isEmpty());

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertRedirect(route('teacher.dashboard'));

    expect($entitlement->customer_user_id)->toBe($customer->id);
});

test('customer overview never includes another learner booking even when assigned to the same teacher', function () {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);

    $ownedBooking = createOverviewBooking($customer, [
        'teacher_id' => $teacher->id,
        'session_goal' => 'My own lesson',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
    ]);
    $otherBooking = createOverviewBooking($otherCustomer, [
        'teacher_id' => $teacher->id,
        'session_goal' => 'Someone else lesson',
        'starts_at' => now()->addDays(1),
        'ends_at' => now()->addDays(1)->addMinutes(30),
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee($otherCustomer->name)
        ->assertViewHas('nextSession', fn ($session): bool => $session?->is($ownedBooking))
        ->assertViewHas('recentActivity', fn ($events): bool => $events->pluck('key')->all() === ['booking:'.$ownedBooking->id]);

    expect($otherBooking->customer_user_id)->toBe($otherCustomer->id);
});
