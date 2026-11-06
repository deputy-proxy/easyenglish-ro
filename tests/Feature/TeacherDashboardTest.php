<?php

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\BookingCallEvent;
use App\Models\SessionFeedback;
use App\Models\TeacherCalendar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function createTeacherDashboardBooking(User $teacher, array $overrides = []): Booking
{
    $startsAt = CarbonImmutable::now('UTC')->addDays(2);

    return Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'bundle_entitlement_id' => null,
        'free_session_claim_id' => null,
        'teacher_id' => $teacher->id,
        'teacher_assigned_at' => now(),
        'customer_name' => 'Dashboard Learner',
        'customer_email' => 'learner-private@example.com',
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practise speaking with confidence',
        'notes' => 'PRIVATE NOTE MUST NOT BE SHOWN TO TEACHER',
        'accepted_policy_version' => '2026-10-25',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(30),
        'duration_minutes' => 30,
        'status' => 'confirmed',
        'locale' => 'en',
        'hold_expires_at' => null,
        'confirmed_at' => now(),
    ], $overrides));
}

test('teacher dashboard requires an active teacher and dashboard routing preserves role separation', function () {
    $customer = User::factory()->create();
    $inactiveTeacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => false]);
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $staffTeacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true, 'is_staff' => true]);

    $this->actingAs($customer)->get(route('teacher.dashboard'))->assertForbidden();
    $inactiveBooking = createTeacherDashboardBooking($inactiveTeacher);
    $this->actingAs($inactiveTeacher)->get(route('teacher.dashboard'))->assertForbidden();
    $this->actingAs($inactiveTeacher)->get(route('teacher.bookings.show', $inactiveBooking->public_reference))->assertForbidden();
    $this->actingAs($teacher)->get(route('dashboard'))->assertRedirect(route('teacher.dashboard'));
    $this->actingAs($inactiveTeacher)->get(route('dashboard'))->assertRedirect(route('teacher.availability'));
    $this->actingAs($staffTeacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.dashboard'))
        ->assertOk()
        ->assertSee(route('filament.admin.pages.dashboard'), false);
    $this->actingAs($teacher)->get('/admin')->assertForbidden();
    $this->actingAs($staffTeacher)->get('/admin')->assertOk();
});

test('teacher overview shows only assigned sessions and minimal learner context', function () {
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $otherTeacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $assigned = createTeacherDashboardBooking($teacher, ['customer_name' => 'Assigned Learner']);
    createTeacherDashboardBooking($otherTeacher, ['customer_name' => 'Unrelated Learner']);

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.dashboard'))
        ->assertOk()
        ->assertSee('Assigned Learner')
        ->assertDontSee('Unrelated Learner')
        ->assertSee('Practise speaking with confidence')
        ->assertDontSee('learner-private@example.com')
        ->assertDontSee('+40722123456')
        ->assertDontSee('PRIVATE NOTE MUST NOT BE SHOWN TO TEACHER')
        ->assertSee(route('teacher.bookings.show', $assigned->public_reference), false);
});

test('teacher overview calculates timezone-aware weekly workload and feedback due tasks', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-09 10:00:00', 'Europe/Bucharest'));
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $today = createTeacherDashboardBooking($teacher, [
        'starts_at' => CarbonImmutable::parse('2026-11-09 12:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-09 12:30:00', 'Europe/Bucharest')->utc(),
    ]);
    $past = createTeacherDashboardBooking($teacher, [
        'starts_at' => CarbonImmutable::parse('2026-11-09 09:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-09 09:30:00', 'Europe/Bucharest')->utc(),
    ]);
    $due = createTeacherDashboardBooking($teacher, [
        'starts_at' => CarbonImmutable::parse('2026-11-09 08:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-09 08:30:00', 'Europe/Bucharest')->utc(),
    ]);
    SessionFeedback::query()->create([
        'booking_id' => $past->id,
        'recorded_by' => $teacher->id,
        'metric_key' => 'overall',
        'feedback' => 'Feedback already submitted.',
    ]);

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.dashboard'))
        ->assertOk()
        ->assertViewHas('todaySessions', fn ($sessions): bool => $sessions->contains('id', $today->id))
        ->assertViewHas('weeklySessionCount', 3)
        ->assertViewHas('weeklyScheduledMinutes', 90)
        ->assertViewHas('feedbackDueCount', 1)
        ->assertSee($due->customer_name)
        ->assertSee(route('teacher.bookings.show', $due->public_reference), false);

    CarbonImmutable::setTestNow();
});

test('teacher session list supports date and status filters with pagination', function () {
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    for ($i = 0; $i < 16; $i++) {
        createTeacherDashboardBooking($teacher, ['customer_name' => 'Assigned '.$i]);
    }
    $otherTeacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    createTeacherDashboardBooking($otherTeacher, ['customer_name' => 'Not Assigned']);

    $past = createTeacherDashboardBooking($teacher, [
        'customer_name' => 'Completed Learner',
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
    ]);

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.dashboard', ['view' => 'sessions', 'period' => 'upcoming', 'status' => 'confirmed']))
        ->assertOk()
        ->assertViewHas('sessions', fn ($sessions): bool => $sessions->total() === 16 && $sessions->count() === 15)
        ->assertDontSee('Not Assigned')
        ->assertSee('Assigned 0');

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.dashboard', ['view' => 'sessions', 'period' => 'past', 'status' => 'completed']))
        ->assertOk()
        ->assertViewHas('sessions', fn ($sessions): bool => $sessions->total() === 1 && $sessions->first()->id === $past->id)
        ->assertSee(__('public.teacher_status_completed'));

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.dashboard', ['view' => 'sessions', 'period' => 'all', 'status' => 'confirmed']))
        ->assertOk()
        ->assertViewHas('sessions', fn ($sessions): bool => $sessions->total() === 16);
});

test('teacher dashboard shows calendar freshness and own availability exceptions', function () {
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    TeacherCalendar::query()->create([
        'user_id' => $teacher->id,
        'calendar_id' => 'primary',
        'calendar_email' => 'teacher-calendar@example.com',
        'access_token' => 'encrypted-access',
        'refresh_token' => 'encrypted-refresh',
        'status' => 'connected',
        'last_synced_at' => now(),
    ]);
    AvailabilityRule::query()->create([
        'user_id' => $teacher->id, 'weekday' => 1, 'starts_at' => '09:00',
        'ends_at' => '12:00', 'is_active' => true,
    ]);
    AvailabilityException::query()->create([
        'user_id' => $teacher->id, 'kind' => 'blocked',
        'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour(),
        'reason' => 'Personal appointment',
    ]);

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.dashboard'))
        ->assertOk()
        ->assertSee(__('public.teacher_calendar_status_connected'))
        ->assertSee(__('public.teacher_active_rules', ['count' => 1]))
        ->assertSee(__('public.teacher_upcoming_exceptions_count', ['count' => 1]))
        ->assertDontSee('Personal appointment');
});

test('teacher dashboard renders Romanian labels from the current session locale', function () {
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);

    $this->actingAs($teacher)->withSession(['locale' => 'ro'])
        ->get(route('teacher.dashboard'))
        ->assertOk()
        ->assertSee('Spațiul profesorului')
        ->assertSee('Sesiunile mele')
        ->assertSee('dark:bg-zinc-950', false);
});

test('teacher session cards show current OpenWA readiness without exposing the join URL', function () {
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $booking = createTeacherDashboardBooking($teacher);
    $joinUrl = 'https://call.openwa.example/session/private-token';

    BookingCallEvent::query()->create([
        'booking_id' => $booking->id,
        'teacher_id' => $teacher->id,
        'provider' => 'openwa',
        'revision' => 1,
        'idempotency_key' => 'teacher-dashboard-'.$booking->id,
        'provider_event_id' => 'provider-'.$booking->id,
        'join_url' => $joinUrl,
        'call_type' => 'video',
        'scheduled_start_at' => $booking->starts_at,
        'scheduled_end_at' => $booking->ends_at,
        'status' => 'scheduled',
        'scheduled_at' => now(),
    ]);

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.dashboard', ['view' => 'sessions']))
        ->assertOk()
        ->assertSee(__('public.teacher_call_status_ready'))
        ->assertDontSee($joinUrl)
        ->assertSee(route('teacher.bookings.show', $booking->public_reference), false);

    $this->actingAs($teacher)->withSession(['locale' => 'en'])
        ->get(route('teacher.bookings.show', $booking->public_reference))
        ->assertOk()
        ->assertSee(__('public.teacher_open_whatsapp_call'));
});

test('teacher session detail respects the Romanian session locale', function () {
    $teacher = User::factory()->create(['is_teacher' => true, 'teacher_active' => true]);
    $booking = createTeacherDashboardBooking($teacher);

    $this->actingAs($teacher)->withSession(['locale' => 'ro'])
        ->get(route('teacher.bookings.show', $booking->public_reference))
        ->assertOk()
        ->assertSee('Detalii sesiune')
        ->assertSee('Cursant');
});
