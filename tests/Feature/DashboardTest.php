<?php

use App\Models\Booking;
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
