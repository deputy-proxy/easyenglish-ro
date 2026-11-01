<?php

use App\Models\Booking;
use App\Models\ProgressReport;
use App\Models\SessionFeedback;
use App\Models\User;
use Illuminate\Support\Str;

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
