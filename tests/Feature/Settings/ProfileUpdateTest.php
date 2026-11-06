<?php

use App\Livewire\Settings\Profile;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/profile')->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('english_level', 'B2')
        ->set('session_goal', 'Lead meetings with international colleagues')
        ->set('session_notes', 'Prefer practical role-play and direct feedback.')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
    expect($user->english_level)->toBe('B2');
    expect($user->session_goal)->toBe('Lead meetings with international colleagues');
    expect($user->session_notes)->toBe('Prefer practical role-play and direct feedback.');
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

test('learner profile fields can be cleared without changing historical booking snapshots', function () {
    $user = User::factory()->create([
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'session_notes' => 'Prepare for meetings',
    ]);
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $user->id,
        'customer_name' => $user->name,
        'customer_email' => $user->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'notes' => 'Prepare for meetings',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'duration_minutes' => 30,
        'status' => 'confirmed',
        'locale' => 'en',
    ]);

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('english_level', '')
        ->set('session_goal', '')
        ->set('session_notes', '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->english_level)->toBeNull()
        ->and($user->fresh()->session_goal)->toBeNull()
        ->and($user->fresh()->session_notes)->toBeNull()
        ->and($booking->fresh()->english_level)->toBe('B1')
        ->and($booking->fresh()->session_goal)->toBe('Work conversations')
        ->and($booking->fresh()->notes)->toBe('Prepare for meetings');
});

test('learner profile validates CEFR levels and bounded learning text', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('english_level', 'C3')
        ->set('session_goal', str_repeat('g', 501))
        ->set('session_notes', str_repeat('n', 5001))
        ->call('updateProfileInformation')
        ->assertHasErrors(['english_level', 'session_goal', 'session_notes']);

    expect($user->fresh()->english_level)->toBeNull()
        ->and($user->fresh()->session_goal)->toBeNull()
        ->and($user->fresh()->session_notes)->toBeNull();
});

test('profile settings use the branded dashboard shell', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('dashboard-topbar')
        ->assertSee('dashboard-panel')
        ->assertSee('EasyEnglish')
        ->assertSee(route('dashboard', ['view' => 'account']), false)
        ->assertDontSee('Repository')
        ->assertDontSee('Documentation');
});
