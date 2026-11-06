<?php

use App\Models\Booking;
use App\Models\ProgressReport;
use App\Models\SessionFeedback;
use App\Models\User;
use App\Services\Progress\ProgressChartData;
use Illuminate\Support\Str;

test('progress assessment history includes complete and partial records with five score dimensions and related sessions', function () {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $metrics = ProgressChartData::METRIC_KEYS;

    $makeBooking = function (User $owner, int $daysAgo): Booking {
        return Booking::query()->create([
            'public_reference' => (string) Str::uuid(),
            'customer_user_id' => $owner->id,
            'customer_name' => $owner->name,
            'customer_email' => $owner->email,
            'customer_phone' => '+40722123456',
            'english_level' => 'B1',
            'session_goal' => 'Practice work conversations',
            'accepted_policy_version' => 'test-policy',
            'starts_at' => now()->subDays($daysAgo),
            'ends_at' => now()->subDays($daysAgo)->addMinutes(30),
            'duration_minutes' => 30,
            'status' => 'completed',
            'locale' => 'en',
        ]);
    };

    $completeBooking = $makeBooking($customer, 12);
    foreach ($metrics as $index => $metric) {
        SessionFeedback::query()->create([
            'booking_id' => $completeBooking->id,
            'metric_key' => $metric,
            'baseline_score' => 25 + $index,
            'current_score' => 50 + $index,
            'potential_score' => 90,
            'feedback' => 'Dimension observation '.$metric,
        ]);
    }

    $partialBooking = $makeBooking($customer, 6);
    SessionFeedback::query()->create([
        'booking_id' => $partialBooking->id,
        'metric_key' => 'vocabulary',
        'current_score' => 61,
        'potential_score' => 88,
    ]);
    SessionFeedback::query()->create([
        'booking_id' => $partialBooking->id,
        'metric_key' => 'pronunciation',
        'current_score' => 0,
    ]);
    SessionFeedback::query()->create([
        'booking_id' => $partialBooking->id,
        'metric_key' => 'overall',
        'feedback' => 'Keep practising short explanations.',
    ]);

    $textOnlyBooking = $makeBooking($customer, 1);
    SessionFeedback::query()->create([
        'booking_id' => $textOnlyBooking->id,
        'metric_key' => 'overall',
        'feedback' => 'Try explaining one decision in English each day.',
    ]);

    $otherBooking = $makeBooking($otherCustomer, 0);
    SessionFeedback::query()->create([
        'booking_id' => $otherBooking->id,
        'metric_key' => 'vocabulary',
        'current_score' => 99,
        'feedback' => 'Another learner private note.',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee(__('public.dashboard_progress_assessment_history'))
        ->assertSee(__('public.dashboard_progress_complete'))
        ->assertSee(__('public.dashboard_progress_incomplete'))
        ->assertSee('Dimension observation vocabulary')
        ->assertSee('Keep practising short explanations.')
        ->assertSee('Try explaining one decision in English each day.')
        ->assertSee(__('public.dashboard_progress_baseline'))
        ->assertSee(__('public.dashboard_progress_current'))
        ->assertSee(__('public.dashboard_potential'))
        ->assertDontSee('Another learner private note.', false)
        ->assertViewHas('assessmentHistory', fn ($history): bool => $history->total() === 3
            && $history->first()->is($textOnlyBooking))
        ->assertViewHas('assessmentSummaries', fn (array $summaries): bool => $summaries[$completeBooking->id]['complete']
            && ! $summaries[$partialBooking->id]['complete']
            && $summaries[$textOnlyBooking->id]['score_count'] === 0
            && str_contains($summaries[$textOnlyBooking->id]['session_url'], $textOnlyBooking->public_reference))
        ->assertViewHas('progressChart', fn (array $chart): bool => $chart['assessment_count'] === 1
            && $chart['partial_count'] === 2);
});

test('assessment history is paginated and keeps the newest ten records on the first page', function () {
    $customer = User::factory()->create();
    $bookings = [];

    for ($index = 0; $index < 12; $index++) {
        $booking = Booking::query()->create([
            'public_reference' => (string) Str::uuid(),
            'customer_user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '+40722123456',
            'english_level' => 'B1',
            'session_goal' => 'Assessment history pagination',
            'accepted_policy_version' => 'test-policy',
            'starts_at' => now()->subDays($index + 1),
            'ends_at' => now()->subDays($index + 1)->addMinutes(15),
            'duration_minutes' => 15,
            'status' => 'completed',
            'locale' => 'en',
        ]);
        $bookings[] = $booking;
        SessionFeedback::query()->create([
            'booking_id' => $booking->id,
            'metric_key' => 'vocabulary',
            'current_score' => 40 + $index,
        ]);
    }

    $firstPage = $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee(__('public.dashboard_progress_assessment_history'))
        ->assertViewHas('assessmentHistory', fn ($history): bool => $history->total() === 12 && $history->count() === 10);

    $firstPage->assertSee('Session #'.$bookings[0]->id)
        ->assertDontSee('Session #'.$bookings[11]->id);

    $this->get(route('dashboard', ['view' => 'progress', 'assessments_page' => 2]))
        ->assertOk()
        ->assertSee('Session #'.$bookings[11]->id)
        ->assertViewHas('assessmentHistory', fn ($history): bool => $history->currentPage() === 2);
});

test('progress report archive is chronological, paginated, and excludes drafts and other learners', function () {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();

    $makeReport = function (User $owner, string $title, ?int $daysAgo): ProgressReport {
        $booking = Booking::query()->create([
            'public_reference' => (string) Str::uuid(),
            'customer_user_id' => $owner->id,
            'customer_name' => $owner->name,
            'customer_email' => $owner->email,
            'customer_phone' => '+40722123456',
            'english_level' => 'B1',
            'session_goal' => 'Review progress',
            'accepted_policy_version' => 'test-policy',
            'starts_at' => now()->subDays($daysAgo ?? 0),
            'ends_at' => now()->subDays($daysAgo ?? 0)->addMinutes(30),
            'duration_minutes' => 30,
            'status' => 'completed',
            'locale' => 'en',
        ]);

        return ProgressReport::query()->create([
            'booking_id' => $booking->id,
            'title' => $title,
            'summary' => 'Published report summary for '.$title,
            'shared_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
        ]);
    };

    $reports = [];
    for ($index = 0; $index < 12; $index++) {
        $reports[] = $makeReport($customer, 'Published report '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), $index);
    }
    $draft = $makeReport($customer, 'Private unshared draft', null);
    $otherReport = $makeReport($otherCustomer, 'Another learner report', 0);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee('Published report 00')
        ->assertSee('Published report 09')
        ->assertDontSee('Published report 10')
        ->assertDontSee('Private unshared draft')
        ->assertDontSee('Another learner report')
        ->assertViewHas('progressReports', fn ($page): bool => $page->total() === 12 && $page->count() === 10)
        ->assertViewHas('progressReportSessionUrls', fn (array $urls): bool => isset($urls[$reports[0]->id])
            && str_contains($urls[$reports[0]->id], $reports[0]->booking->public_reference));

    $this->get(route('dashboard', ['view' => 'progress', 'reports_page' => 2]))
        ->assertOk()
        ->assertSee('Published report 10')
        ->assertSee('Published report 11')
        ->assertViewHas('progressReports', fn ($page): bool => $page->currentPage() === 2);

    expect($draft->shared_at)->toBeNull()
        ->and($otherReport->exists)->toBeTrue();
});

test('published progress report detail includes all shared sections and a link to its related session', function () {
    $customer = User::factory()->create();
    $author = User::factory()->create();
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Prepare for presentations',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->subDays(4),
        'ends_at' => now()->subDays(4)->addMinutes(30),
        'duration_minutes' => 30,
        'status' => 'completed',
        'locale' => 'en',
    ]);
    $report = ProgressReport::query()->create([
        'booking_id' => $booking->id,
        'title' => 'Quarterly speaking review',
        'overall_level' => 'B2',
        'summary' => 'You can now sustain longer professional conversations.',
        'strengths' => 'Clear pronunciation and strong vocabulary range.',
        'areas_to_improve' => 'Use more complex conditional structures.',
        'recommendations' => 'Practise a five-minute project update twice this week.',
        'shared_at' => now()->subDay(),
        'created_by' => $author->id,
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress', 'report' => $report->id]))
        ->assertOk()
        ->assertSee('Quarterly speaking review')
        ->assertSee('B2')
        ->assertSee('You can now sustain longer professional conversations.')
        ->assertSee('Clear pronunciation and strong vocabulary range.')
        ->assertSee('Use more complex conditional structures.')
        ->assertSee('Practise a five-minute project update twice this week.')
        ->assertSee(__('public.dashboard_progress_written_by', ['name' => $author->name]))
        ->assertSee(__('public.dashboard_progress_related_session'))
        ->assertViewHas('selectedProgressReport', fn ($selected): bool => $selected?->is($report))
        ->assertViewHas('selectedProgressReportSessionUrl', fn (?string $url): bool => is_string($url)
            && str_contains($url, $booking->public_reference));
});

test('customers cannot open another learner report or an unshared draft by changing the report query parameter', function () {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();

    $makeReport = function (User $owner, ?DateTimeInterface $sharedAt): ProgressReport {
        $booking = Booking::query()->create([
            'public_reference' => (string) Str::uuid(),
            'customer_user_id' => $owner->id,
            'customer_name' => $owner->name,
            'customer_email' => $owner->email,
            'customer_phone' => '+40722123456',
            'english_level' => 'B1',
            'session_goal' => 'Private report test',
            'accepted_policy_version' => 'test-policy',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addMinutes(15),
            'duration_minutes' => 15,
            'status' => 'completed',
            'locale' => 'en',
        ]);

        return ProgressReport::query()->create([
            'booking_id' => $booking->id,
            'title' => 'Private report content',
            'summary' => 'Private report summary.',
            'shared_at' => $sharedAt,
        ]);
    };

    $otherReport = $makeReport($otherCustomer, now());
    $draftReport = $makeReport($customer, null);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress', 'report' => $otherReport->id]))
        ->assertNotFound();

    $this->get(route('dashboard', ['view' => 'progress', 'report' => $draftReport->id]))
        ->assertNotFound();
});

test('progress chart never compares improvement against an incomplete baseline', function () {
    $customer = User::factory()->create();
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Baseline comparison',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(30),
        'duration_minutes' => 30,
        'status' => 'completed',
        'locale' => 'en',
    ]);

    foreach (ProgressChartData::METRIC_KEYS as $index => $metric) {
        SessionFeedback::query()->create([
            'booking_id' => $booking->id,
            'metric_key' => $metric,
            'baseline_score' => $index === 4 ? null : 20 + $index,
            'current_score' => 50 + $index,
        ]);
    }

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertViewHas('progressChart', fn (array $chart): bool => count($chart['baseline_scores']) === 4
            && $chart['strongest_improvement'] === null);
});

test('progress report and assessment history labels are localized in Romanian', function () {
    $customer = User::factory()->create();
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Exersează conversațiile',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'completed',
        'locale' => 'ro',
    ]);
    SessionFeedback::query()->create([
        'booking_id' => $booking->id,
        'metric_key' => 'overall',
        'feedback' => 'Continuă să exersezi.',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'ro'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee('Istoricul evaluărilor')
        ->assertSee('Rapoarte de progres')
        ->assertSee('Evaluare incompletă')
        ->assertSee('Continuă să exersezi.');
});

test('next steps use the latest published report recommendation and never expose draft recommendations', function () {
    $customer = User::factory()->create();
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Improve presentations',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'completed',
        'locale' => 'en',
    ]);

    ProgressReport::query()->create([
        'booking_id' => $booking->id,
        'title' => 'Published recommendations',
        'summary' => 'A published report.',
        'recommendations' => 'Practise opening and closing a short presentation.',
        'shared_at' => now()->subHour(),
    ]);
    ProgressReport::query()->create([
        'booking_id' => $booking->id,
        'title' => 'Private draft',
        'summary' => 'Not shared.',
        'recommendations' => 'This draft recommendation must stay private.',
        'shared_at' => null,
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee(__('public.dashboard_progress_next_steps'))
        ->assertSee('Practise opening and closing a short presentation.')
        ->assertDontSee('This draft recommendation must stay private.');
});

test('next steps fall back to recorded teacher feedback and show a neutral state when no guidance exists', function () {
    $customer = User::factory()->create();
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Build speaking confidence',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'completed',
        'locale' => 'en',
    ]);

    SessionFeedback::query()->create([
        'booking_id' => $booking->id,
        'metric_key' => 'overall',
        'feedback' => 'Practise giving a short daily update.',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee(__('public.dashboard_progress_overall_feedback'))
        ->assertSee('Practise giving a short daily update.');

    $otherCustomer = User::factory()->create();

    $this->actingAs($otherCustomer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee(__('public.dashboard_progress_next_steps_empty'))
        ->assertDontSee('Practise giving a short daily update.');
});

test('progress view explains that score trends are descriptive rather than guaranteed outcomes', function () {
    $customer = User::factory()->create();
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practice English',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'completed',
        'locale' => 'en',
    ]);

    foreach (ProgressChartData::METRIC_KEYS as $index => $metric) {
        SessionFeedback::query()->create([
            'booking_id' => $booking->id,
            'metric_key' => $metric,
            'baseline_score' => 20 + $index,
            'current_score' => 30 + $index,
        ]);
    }

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertSee(__('public.dashboard_progress_context_note'))
        ->assertSee(__('public.dashboard_progress_data_table'));
});

test('radar chart keeps the baseline and only the three newest complete assessments while totals cover full history', function () {
    $customer = User::factory()->create();
    $metrics = ProgressChartData::METRIC_KEYS;
    $bookings = [];

    foreach ([40, 30, 20, 10] as $index => $daysAgo) {
        $booking = Booking::query()->create([
            'public_reference' => (string) Str::uuid(),
            'customer_user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '+40722123456',
            'english_level' => 'B1',
            'session_goal' => 'Longitudinal chart test',
            'accepted_policy_version' => 'test-policy',
            'starts_at' => now()->subDays($daysAgo),
            'ends_at' => now()->subDays($daysAgo)->addMinutes(30),
            'duration_minutes' => 30,
            'status' => 'completed',
            'locale' => 'en',
        ]);
        $bookings[] = $booking;

        foreach ($metrics as $metricIndex => $metric) {
            SessionFeedback::query()->create([
                'booking_id' => $booking->id,
                'metric_key' => $metric,
                'baseline_score' => 10 + $metricIndex + ($index * 10),
                'current_score' => 30 + $metricIndex + ($index * 10),
                'potential_score' => 95,
            ]);
        }
    }

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'progress']))
        ->assertOk()
        ->assertViewHas('progressChart', function (array $chart) use ($bookings): bool {
            $assessmentIds = collect($chart['series'])
                ->where('type', 'assessment')
                ->pluck('id')
                ->all();

            return $chart['assessment_count'] === 4
                && count($chart['series']) === 4
                && $chart['series'][0]['type'] === 'baseline'
                && $assessmentIds === [(string) $bookings[1]->id, (string) $bookings[2]->id, (string) $bookings[3]->id]
                && $chart['baseline_scores']['vocabulary'] === 10
                && ! array_key_exists('potential', $chart['series'][1]['scores']);
        });
});
