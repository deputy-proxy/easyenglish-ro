<?php

use App\Enums\NotificationEvent;
use App\Jobs\Calendar\SyncBookingCalendarEvent;
use App\Jobs\Calendar\SyncTeacherCalendar;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\NotificationDelivery;
use App\Models\TeacherCalendar;
use App\Models\TeacherCalendarBusyInterval;
use App\Models\User;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\Notifications\NotificationMessageFactory;
use App\Services\Notifications\WhatsAppMessageFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function createCalendarTeacher(string $name, string $email, array $options = []): User
{
    $teacher = User::factory()->create([
        'name' => $name,
        'email' => $email,
        'phone' => $options['phone'] ?? null,
        'is_teacher' => true,
        'teacher_active' => $options['active'] ?? true,
    ]);

    TeacherCalendar::query()->create([
        'user_id' => $teacher->id,
        'calendar_id' => $options['calendar_id'] ?? 'primary',
        'calendar_email' => $email,
        'access_token' => 'encrypted-access-token',
        'refresh_token' => 'encrypted-refresh-token',
        'token_expires_at' => $options['token_expires_at'] ?? now()->addHour(),
        'status' => $options['status'] ?? 'connected',
        'last_synced_at' => $options['last_synced_at'] ?? now(),
    ]);

    return $teacher->fresh('teacherCalendar');
}

function addTeacherWindow(User $teacher, string $date, string $start = '09:00', string $end = '11:00'): AvailabilityRule
{
    return AvailabilityRule::query()->create([
        'user_id' => $teacher->id,
        'weekday' => CarbonImmutable::parse($date, 'Europe/Bucharest')->dayOfWeekIso % 7,
        'starts_at' => $start,
        'ends_at' => $end,
        'is_active' => true,
    ]);
}

function confirmedTeacherBooking(User $teacher, array $overrides = []): Booking
{
    $start = CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Bucharest')->utc();

    return Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'teacher_id' => $teacher->id,
        'teacher_assigned_at' => now(),
        'customer_name' => 'Learner Example',
        'customer_email' => 'learner@example.com',
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practise speaking',
        'accepted_policy_version' => '2026-10-25',
        'starts_at' => $start,
        'ends_at' => $start->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 08:00:00', 'Europe/Bucharest'));
    config()->set('easyenglish.teacher_availability_enabled', true);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('public availability is the union of eligible teacher slots without disclosing teacher details', function () {
    $teacher = createCalendarTeacher('Private Teacher', 'private.teacher@example.com');
    addTeacherWindow($teacher, '2026-11-02');

    $response = $this->getJson('/availability?week=2026-11-02')->assertOk();

    expect($response->json('days.0.slots'))->toHaveCount(4)
        ->and($response->json('timezone'))->toBe('Europe/Bucharest')
        ->and($response->getContent())->not->toContain('Private Teacher')
        ->and($response->getContent())->not->toContain('private.teacher@example.com')
        ->and(array_key_exists('teacher', $response->json()))->toBeFalse()
        ->and(array_key_exists('teacher_id', $response->json()))->toBeFalse();
});

test('public availability is the deduplicated union of multiple teachers schedules', function () {
    $first = createCalendarTeacher('Union First', 'union.first@example.com');
    $second = createCalendarTeacher('Union Second', 'union.second@example.com');
    addTeacherWindow($first, '2026-11-02', '09:00', '10:00');
    addTeacherWindow($second, '2026-11-02', '09:30', '10:30');

    $slots = app(AvailabilityService::class)->slotsForDate('2026-11-02');

    expect($slots->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['09:00', '09:30', '10:00']);
});

test('teacher weekly availability preserves overnight windows across local dates', function () {
    $teacher = createCalendarTeacher('Overnight Teacher', 'overnight.teacher@example.com');
    addTeacherWindow($teacher, '2026-11-02', '22:00', '02:00');

    expect(app(AvailabilityService::class)->slotsForDate('2026-11-02')->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['22:00', '22:30', '23:00', '23:30'])
        ->and(app(AvailabilityService::class)->slotsForDate('2026-11-03')->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['00:00', '00:30', '01:00', '01:30']);
});

test('date-specific overrides replace weekly availability and blocked periods take precedence', function () {
    $teacher = createCalendarTeacher('Exception Teacher', 'exception.teacher@example.com');
    addTeacherWindow($teacher, '2026-11-02', '09:00', '11:00');
    AvailabilityException::query()->create([
        'user_id' => $teacher->id,
        'kind' => 'availability_override',
        'starts_at' => CarbonImmutable::parse('2026-11-02 13:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-02 14:00:00', 'Europe/Bucharest')->utc(),
    ]);
    expect(fn () => AvailabilityException::query()->create([
        'user_id' => $teacher->id,
        'kind' => 'availability_override',
        'starts_at' => CarbonImmutable::parse('2026-11-02 13:30:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-02 14:30:00', 'Europe/Bucharest')->utc(),
    ]))->toThrow(ValidationException::class);

    AvailabilityException::query()->create([
        'user_id' => $teacher->id,
        'kind' => 'blocked',
        'starts_at' => CarbonImmutable::parse('2026-11-02 13:30:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-02 13:45:00', 'Europe/Bucharest')->utc(),
    ]);

    expect(app(AvailabilityService::class)->slotsForDate('2026-11-02')->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['13:00']);
});

test('Busy Google Calendar events block the session and buffer while free and cancelled events do not', function () {
    $teacher = createCalendarTeacher('Busy Teacher', 'busy.teacher@example.com');
    addTeacherWindow($teacher, '2026-11-02');

    TeacherCalendarBusyInterval::query()->create([
        'user_id' => $teacher->id,
        'google_event_id' => 'busy-1',
        'starts_at' => CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-02 09:45:00', 'Europe/Bucharest')->utc(),
    ]);

    $slots = app(AvailabilityService::class)->slotsForDate('2026-11-02');

    expect($slots->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['10:00', '10:30']);
});

test('legacy bookings without a teacher remain global blockers for every teacher', function () {
    $first = createCalendarTeacher('Legacy Blocker First', 'legacy.blocker.first@example.com');
    $second = createCalendarTeacher('Legacy Blocker Second', 'legacy.blocker.second@example.com');
    addTeacherWindow($first, '2026-11-02');
    addTeacherWindow($second, '2026-11-02');
    confirmedTeacherBooking($first, ['teacher_id' => null, 'teacher_assigned_at' => null]);

    expect(app(AvailabilityService::class)->slotsForDate('2026-11-02')->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['09:30', '10:00', '10:30']);
});

test('stale or errored teacher calendars fail closed', function () {
    $stale = createCalendarTeacher('Stale Teacher', 'stale.teacher@example.com', ['last_synced_at' => now()->subMinutes(11)]);
    $errored = createCalendarTeacher('Errored Teacher', 'errored.teacher@example.com', ['status' => 'error']);
    $inactive = createCalendarTeacher('Inactive Teacher', 'inactive.teacher@example.com', ['active' => false]);
    AvailabilityRule::query()->create(['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'is_active' => true]);
    addTeacherWindow($stale, '2026-11-02');
    addTeacherWindow($errored, '2026-11-02');
    addTeacherWindow($inactive, '2026-11-02');

    expect(app(AvailabilityService::class)->slotsForDate('2026-11-02'))->toBeEmpty();
});

test('booking holds are assigned fairly and a teacher cannot receive overlapping holds', function () {
    $first = createCalendarTeacher('First Teacher', 'first.teacher@example.com');
    $second = createCalendarTeacher('Second Teacher', 'second.teacher@example.com');
    addTeacherWindow($first, '2026-11-02');
    addTeacherWindow($second, '2026-11-02');

    $service = app(BookingService::class);
    $start = CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Bucharest');
    $firstBooking = $service->holdSlot([
        'name' => 'Learner One', 'email' => 'learner.one@example.com', 'phone' => '+40722123451',
        'english_level' => 'B1', 'session_goal' => 'Speaking', 'accepted_policy_version' => '2026-10-25',
    ], $start, freeIntroduction: true, emailVerified: true, phoneVerified: true);
    $secondBooking = $service->holdSlot([
        'name' => 'Learner Two', 'email' => 'learner.two@example.com', 'phone' => '+40722123452',
        'english_level' => 'B1', 'session_goal' => 'Speaking', 'accepted_policy_version' => '2026-10-25',
    ], $start, freeIntroduction: true, emailVerified: true, phoneVerified: true);

    expect($firstBooking->teacher_id)->toBe($first->id)
        ->and($secondBooking->teacher_id)->toBe($second->id)
        ->and($firstBooking->teacher_assigned_at)->not->toBeNull()
        ->and(BookingAudit::query()->where('booking_id', $firstBooking->id)->where('action', 'booking.held')->first()->metadata['teacher_id'])->toBe($first->id)
        ->and(fn () => $service->holdSlot([
            'name' => 'Learner Three', 'email' => 'learner.three@example.com', 'phone' => '+40722123453',
            'english_level' => 'B1', 'session_goal' => 'Speaking', 'accepted_policy_version' => '2026-10-25',
        ], $start, freeIntroduction: true, emailVerified: true, phoneVerified: true))->toThrow(ValidationException::class);
});

test('confirmation revalidates assignment and moves a hold to another eligible teacher', function () {
    $first = createCalendarTeacher('First Teacher', 'first.confirm@example.com');
    $second = createCalendarTeacher('Second Teacher', 'second.confirm@example.com');
    addTeacherWindow($first, '2026-11-02');
    addTeacherWindow($second, '2026-11-02');
    Queue::fake();
    $service = app(BookingService::class);
    $booking = $service->holdSlot([
        'name' => 'Learner', 'email' => 'revalidate@example.com', 'phone' => '+40722123454',
        'english_level' => 'B1', 'session_goal' => 'Speaking', 'accepted_policy_version' => '2026-10-25',
    ], CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Bucharest'), freeIntroduction: true, emailVerified: true, phoneVerified: true);

    expect($booking->teacher_id)->toBe($first->id);

    TeacherCalendarBusyInterval::query()->create([
        'user_id' => $first->id,
        'google_event_id' => 'new-calendar-conflict',
        'starts_at' => CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-02 09:30:00', 'Europe/Bucharest')->utc(),
    ]);

    $confirmed = $service->confirm($booking->id);

    $confirmationAudit = BookingAudit::query()->where('booking_id', $booking->id)->where('action', 'booking.confirmed')->first();

    expect($confirmed->status)->toBe('confirmed')
        ->and($confirmed->teacher_id)->toBe($second->id)
        ->and($confirmationAudit->metadata['previous_teacher_id'])->toBe($first->id)
        ->and($confirmationAudit->metadata['teacher_id'])->toBe($second->id)
        ->and($confirmationAudit->metadata['teacher_reassigned_at_confirmation'])->toBeTrue();
});

test('confirmation fails safely when no teacher remains eligible', function () {
    Queue::fake();
    $teacher = createCalendarTeacher('Unavailable Teacher', 'unavailable.teacher@example.com');
    addTeacherWindow($teacher, '2026-11-02');
    $service = app(BookingService::class);
    $booking = $service->holdSlot([
        'name' => 'Learner', 'email' => 'no.teacher@example.com', 'phone' => '+40722123456',
        'english_level' => 'B1', 'session_goal' => 'Speaking', 'accepted_policy_version' => '2026-10-25',
    ], CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Bucharest'), freeIntroduction: true, emailVerified: true, phoneVerified: true);
    $teacher->update(['teacher_active' => false]);

    expect(fn () => $service->confirm($booking->id))->toThrow(ValidationException::class)
        ->and($booking->fresh()->status)->toBe('pending');
});

test('rescheduling keeps the current teacher when possible and reassigns when they are no longer eligible', function () {
    Queue::fake();
    $first = createCalendarTeacher('Continuity First', 'continuity.first@example.com');
    $second = createCalendarTeacher('Continuity Second', 'continuity.second@example.com');
    addTeacherWindow($first, '2026-11-02');
    addTeacherWindow($first, '2026-11-04');
    addTeacherWindow($second, '2026-11-04');
    $booking = confirmedTeacherBooking($first);
    $service = app(BookingService::class);
    $staff = User::factory()->create(['is_staff' => true]);

    $rescheduled = $service->rescheduleByStaff($booking->id, CarbonImmutable::parse('2026-11-04 09:00:00', 'Europe/Bucharest'), $staff->id, 'Preserve teacher continuity');
    expect($rescheduled->teacher_id)->toBe($first->id);

    TeacherCalendarBusyInterval::query()->create([
        'user_id' => $first->id,
        'google_event_id' => 'continuity-conflict',
        'starts_at' => CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-04 10:30:00', 'Europe/Bucharest')->utc(),
    ]);
    $reassigned = $service->rescheduleByStaff($booking->id, CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Bucharest'), $staff->id, 'Reassign due to calendar conflict');

    expect($reassigned->teacher_id)->toBe($second->id)
        ->and($reassigned->teacher_assigned_at)->not->toBeNull();
});

test('teacher portal is authenticated and isolated to teacher accounts', function () {
    $ordinaryUser = User::factory()->create();
    $teacher = createCalendarTeacher('Portal Teacher', 'portal.teacher@example.com');
    $otherTeacher = createCalendarTeacher('Other Teacher', 'other.teacher@example.com');
    addTeacherWindow($teacher, '2026-11-02');
    addTeacherWindow($otherTeacher, '2026-11-02', '13:00', '15:00');

    $this->actingAs($ordinaryUser)->get('/teacher/availability')->assertForbidden();
    $this->actingAs($teacher)->get('/teacher/availability')->assertOk()
        ->assertSee('09:00')
        ->assertDontSee('13:00');
});

test('legacy availability backfill preserves global records and skips teachers with their own schedule', function () {
    $globalRule = AvailabilityRule::query()->create([
        'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00', 'is_active' => true,
    ]);
    $globalException = AvailabilityException::query()->create([
        'kind' => 'blocked',
        'starts_at' => CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Bucharest')->utc(),
        'reason' => 'Holiday',
    ]);
    $toBackfill = User::factory()->create(['is_teacher' => true]);
    $alreadyConfigured = User::factory()->create(['is_teacher' => true]);
    addTeacherWindow($alreadyConfigured, '2026-11-02', '13:00', '15:00');

    $this->artisan('teachers:backfill-availability')->assertSuccessful();

    expect($toBackfill->availabilityRules()->count())->toBe(1)
        ->and($toBackfill->availabilityRules()->first()->weekday)->toBe($globalRule->weekday)
        ->and($toBackfill->availabilityExceptions()->count())->toBe(1)
        ->and($alreadyConfigured->availabilityRules()->count())->toBe(1)
        ->and(AvailabilityRule::query()->whereNull('user_id')->count())->toBe(1)
        ->and(AvailabilityException::query()->whereNull('user_id')->count())->toBe(1)
        ->and($globalException->exists)->toBeTrue();
});

test('Google Calendar full sync stores only Busy intervals and expands recurring instances', function () {
    $teacher = createCalendarTeacher('Calendar Sync', 'calendar.sync@example.com', [
        'calendar_id' => 'calendar.sync@example.com',
        'token_expires_at' => now()->subMinute(),
    ]);
    $calendar = $teacher->teacherCalendar;

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed-access-token', 'expires_in' => 3600], 200),
        'https://www.googleapis.com/calendar/v3/calendars/calendar.sync%40example.com/events*' => Http::sequence()
            ->push(['items' => [
                ['id' => 'busy-event', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-11-02T09:00:00+02:00'], 'end' => ['dateTime' => '2026-11-02T09:30:00+02:00']],
                ['id' => 'free-event', 'status' => 'confirmed', 'transparency' => 'transparent', 'start' => ['dateTime' => '2026-11-02T10:00:00+02:00'], 'end' => ['dateTime' => '2026-11-02T10:30:00+02:00']],
                ['id' => 'cancelled-event', 'status' => 'cancelled', 'start' => ['dateTime' => '2026-11-02T11:00:00+02:00'], 'end' => ['dateTime' => '2026-11-02T11:30:00+02:00']],
                ['id' => 'all-day-event', 'status' => 'confirmed', 'start' => ['date' => '2026-11-03'], 'end' => ['date' => '2026-11-04']],
                ['id' => 'recurring-instance-20261102T120000', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-11-02T12:00:00+02:00'], 'end' => ['dateTime' => '2026-11-02T12:30:00+02:00']],
                ['id' => 'timezone-event', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-11-02T13:00:00', 'timeZone' => 'Europe/Bucharest'], 'end' => ['dateTime' => '2026-11-02T13:30:00', 'timeZone' => 'Europe/Bucharest']],
            ]], 200)
            ->push(['items' => [
                ['id' => 'busy-event', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-11-02T09:30:00+02:00'], 'end' => ['dateTime' => '2026-11-02T10:00:00+02:00']],
            ]], 200)
            ->push(['items' => []], 200),
    ]);

    app(GoogleCalendarService::class)->sync($calendar);

    expect(TeacherCalendarBusyInterval::query()->where('user_id', $teacher->id)->count())->toBe(4)
        ->and(TeacherCalendarBusyInterval::query()->where('google_event_id', 'busy-event')->exists())->toBeTrue()
        ->and(TeacherCalendarBusyInterval::query()->where('google_event_id', 'all-day-event')->first()->starts_at->utc()->toDateTimeString())->toBe('2026-11-02 22:00:00')
        ->and(TeacherCalendarBusyInterval::query()->where('google_event_id', 'timezone-event')->first()->starts_at->utc()->toDateTimeString())->toBe('2026-11-02 11:00:00')
        ->and($calendar->fresh()->status)->toBe('connected')
        ->and($calendar->fresh()->last_synced_at)->not->toBeNull()
        ->and($calendar->fresh()->access_token)->toBe('refreshed-access-token');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/events?') && $request['singleEvents'] === 'true');

    app(GoogleCalendarService::class)->sync($calendar->fresh());
    expect(TeacherCalendarBusyInterval::query()->where('user_id', $teacher->id)->count())->toBe(1)
        ->and(TeacherCalendarBusyInterval::query()->where('google_event_id', 'busy-event')->first()->starts_at->utc()->toDateTimeString())->toBe('2026-11-02 07:30:00');

    app(GoogleCalendarService::class)->sync($calendar->fresh());
    expect(TeacherCalendarBusyInterval::query()->where('user_id', $teacher->id)->count())->toBe(0);
});

test('confirmed booking events are idempotently created, moved between teacher calendars, and deleted on cancellation', function () {
    $first = createCalendarTeacher('First Calendar', 'first.calendar@example.com', ['calendar_id' => 'first.calendar@example.com']);
    $second = createCalendarTeacher('Second Calendar', 'second.calendar@example.com', ['calendar_id' => 'second.calendar@example.com']);
    $booking = confirmedTeacherBooking($first);

    Http::fake(function (Request $request) {
        if ($request->method() === 'PATCH') {
            static $firstCalendarPatches = 0;
            if (str_contains($request->url(), 'first.calendar%40example.com')) {
                $firstCalendarPatches++;

                return Http::response([], $firstCalendarPatches === 1 ? 404 : 200);
            }

            return Http::response([], 404);
        }
        if ($request->method() === 'DELETE') {
            return Http::response([], 204);
        }

        return Http::response([], 200);
    });

    $calendar = app(GoogleCalendarService::class);
    $calendar->synchronizeBooking($booking);
    $booking->refresh();

    expect($booking->google_calendar_user_id)->toBe($first->id)
        ->and($booking->google_calendar_id)->toBe('first.calendar@example.com')
        ->and($booking->google_calendar_event_id)->not->toBeNull()
        ->and($booking->calendar_sync_error)->toBeNull();

    $firstEventId = $booking->google_calendar_event_id;
    $calendar->synchronizeBooking($booking->fresh());
    expect($booking->fresh()->google_calendar_event_id)->toBe($firstEventId);

    $booking->update(['teacher_id' => $second->id, 'teacher_assigned_at' => now()]);
    $calendar->synchronizeBooking($booking->fresh());

    expect($booking->fresh()->google_calendar_user_id)->toBe($second->id)
        ->and($booking->fresh()->google_calendar_id)->toBe('second.calendar@example.com');

    $booking->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    $calendar->synchronizeBooking($booking->fresh());

    expect($booking->fresh()->google_calendar_event_id)->toBeNull()
        ->and($booking->fresh()->google_calendar_user_id)->toBeNull()
        ->and($booking->fresh()->calendar_synced_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && ! isset($request['id']));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && isset($request['id']));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'first.calendar%40example.com'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'second.calendar%40example.com'));
});

test('confirmed customer views and notifications disclose the assigned teacher only after confirmation', function () {
    $teacher = createCalendarTeacher('Assigned Teacher', 'assigned.teacher@example.com', ['phone' => '+40722123455']);
    $booking = confirmedTeacherBooking($teacher, ['locale' => 'en']);
    $delivery = NotificationDelivery::query()->create([
        'booking_id' => $booking->id,
        'channel' => 'whatsapp',
        'event' => NotificationEvent::AppointmentConfirmed->value,
        'status' => 'pending',
        'idempotency_key' => 'teacher-confirmation-'.$booking->id,
    ]);
    config()->set('services.whatsapp.templates.appointment_confirmation', 'easyenglish_appointment_confirmation');
    config()->set('services.whatsapp.template_languages.en', 'en_US');

    $email = app(NotificationMessageFactory::class)->make($delivery);
    $whatsapp = app(WhatsAppMessageFactory::class)->make($delivery);
    $confirmationUrl = URL::temporarySignedRoute('booking.confirmed', now()->addHour(), ['reference' => $booking->public_reference], false);

    expect(collect($email['details'])->pluck('value')->implode(' '))->toContain('Assigned Teacher')
        ->and($whatsapp['components'][0]['parameters'])->toHaveCount(4)
        ->and($whatsapp['components'][0]['parameters'][3]['text'])->toContain('assigned.teacher@example.com')
        ->and($whatsapp['components'][0]['parameters'][3]['text'])->toContain('+40722123455');

    $this->get($confirmationUrl)->assertOk()->assertSee('Assigned Teacher')->assertSee('assigned.teacher@example.com');
});

test('teacher schedule changes are scoped, support multiple intervals, and roll back overlapping updates', function () {
    $teacher = createCalendarTeacher('Schedule Owner', 'schedule.owner@example.com');
    $otherTeacher = createCalendarTeacher('Other Schedule Owner', 'other.schedule.owner@example.com');
    addTeacherWindow($otherTeacher, '2026-11-02', '13:00', '15:00');

    $this->actingAs($teacher)->put('/teacher/availability', ['availability' => [
        ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00', 'is_active' => '1'],
        ['weekday' => 1, 'starts_at' => '10:30', 'ends_at' => '11:30', 'is_active' => '1'],
    ]])->assertRedirect()->assertSessionHas('status');

    expect($teacher->availabilityRules()->count())->toBe(2)
        ->and($otherTeacher->availabilityRules()->count())->toBe(1);

    $this->actingAs($teacher)->put('/teacher/availability', ['availability' => [
        ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '10:30', 'is_active' => '1'],
        ['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '11:30', 'is_active' => '1'],
    ]])->assertSessionHasErrors('starts_at');

    expect($teacher->fresh()->availabilityRules()->count())->toBe(2)
        ->and($otherTeacher->fresh()->availabilityRules()->count())->toBe(1);

});

test('teacher accounts with booking history or an active calendar connection cannot be deleted', function () {
    $teacherWithBooking = createCalendarTeacher('Protected Teacher', 'protected.teacher@example.com');
    confirmedTeacherBooking($teacherWithBooking);

    expect(fn () => $teacherWithBooking->delete())->toThrow(ValidationException::class)
        ->and(User::query()->whereKey($teacherWithBooking->id)->exists())->toBeTrue();

    $teacherWithCalendar = createCalendarTeacher('Calendar Only Teacher', 'calendar.only@example.com');
    expect(fn () => $teacherWithCalendar->delete())->toThrow(ValidationException::class)
        ->and(User::query()->whereKey($teacherWithCalendar->id)->exists())->toBeTrue();
});

test('staff can manage teacher accounts while teachers remain outside the admin panel', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $teacher = createCalendarTeacher('Admin Managed Teacher', 'admin.managed.teacher@example.com');

    $this->actingAs($staff)->get('/admin/teachers')->assertOk()->assertSee('Admin Managed Teacher');
    $this->actingAs($teacher)->get('/admin/teachers')->assertForbidden();
});

test('calendar reconciliation continues for connected inactive teachers during rollout', function () {
    $inactive = createCalendarTeacher('Inactive Sync Teacher', 'inactive.sync@example.com', ['active' => false]);
    Queue::fake();

    $this->artisan('teachers:sync-calendars')->assertSuccessful();

    Queue::assertPushed(SyncTeacherCalendar::class, fn ($job): bool => $job->teacherCalendarId === $inactive->teacherCalendar->id);
});

test('calendar-event reconciliation queues missing future events and failed cancellation cleanup only', function () {
    $teacher = createCalendarTeacher('Reconciliation Teacher', 'reconciliation.teacher@example.com');
    $missingEvent = confirmedTeacherBooking($teacher);
    $alreadySynced = confirmedTeacherBooking($teacher, [
        'google_calendar_user_id' => $teacher->id,
        'google_calendar_id' => 'primary',
        'google_calendar_event_id' => 'already-synced-event',
        'calendar_synced_at' => now(),
        'teacher_assigned_at' => now()->subHour(),
    ]);
    $cancelledAndSynced = confirmedTeacherBooking($teacher, [
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'google_calendar_user_id' => $teacher->id,
        'google_calendar_id' => 'primary',
        'google_calendar_event_id' => 'cancelled-event',
        'calendar_synced_at' => now(),
    ]);
    $cancelledBeforeFirstSync = confirmedTeacherBooking($teacher, [
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'calendar_synced_at' => null,
    ]);
    $cancelledWithError = confirmedTeacherBooking($teacher, [
        'status' => 'cancelled',
        'cancelled_at' => now()->subMinute(),
        'google_calendar_user_id' => $teacher->id,
        'google_calendar_id' => 'primary',
        'google_calendar_event_id' => 'failed-delete-event',
        'calendar_synced_at' => now(),
        'calendar_sync_error' => 'Google API unavailable',
    ]);
    Queue::fake();

    $this->artisan('bookings:reconcile-calendar-events')->assertSuccessful();

    Queue::assertPushed(SyncBookingCalendarEvent::class, fn ($job): bool => $job->bookingId === $missingEvent->id);
    Queue::assertPushed(SyncBookingCalendarEvent::class, fn ($job): bool => $job->bookingId === $cancelledWithError->id);
    Queue::assertNotPushed(SyncBookingCalendarEvent::class, fn ($job): bool => $job->bookingId === $alreadySynced->id);
    Queue::assertNotPushed(SyncBookingCalendarEvent::class, fn ($job): bool => $job->bookingId === $cancelledAndSynced->id);
});

test('teachers cannot disconnect calendars needed by future bookings or pending event removal', function () {
    $teacher = createCalendarTeacher('Future Booking Teacher', 'future.booking.teacher@example.com');
    confirmedTeacherBooking($teacher);

    expect(fn () => app(GoogleCalendarService::class)->disconnect($teacher))->toThrow(ValidationException::class);

    $newTeacher = createCalendarTeacher('Reassigned Teacher', 'reassigned.teacher@example.com');
    $oldCalendarOwner = createCalendarTeacher('Old Calendar Owner', 'old.calendar.owner@example.com');
    confirmedTeacherBooking($newTeacher, [
        'google_calendar_user_id' => $oldCalendarOwner->id,
        'google_calendar_id' => 'old.calendar.owner@example.com',
        'google_calendar_event_id' => 'event-awaiting-reassignment-removal',
    ]);

    expect(fn () => app(GoogleCalendarService::class)->disconnect($oldCalendarOwner))->toThrow(ValidationException::class);

    $cancelledTeacher = createCalendarTeacher('Pending Removal Teacher', 'pending.removal.teacher@example.com');
    confirmedTeacherBooking($cancelledTeacher, [
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'google_calendar_user_id' => $cancelledTeacher->id,
        'google_calendar_id' => 'primary',
        'google_calendar_event_id' => 'event-awaiting-removal',
        'calendar_synced_at' => null,
    ]);

    expect(fn () => app(GoogleCalendarService::class)->disconnect($cancelledTeacher))->toThrow(ValidationException::class);
});

test('calendar disconnect revokes local access and clears cached Busy intervals when safe', function () {
    $teacher = createCalendarTeacher('Disconnect Teacher', 'disconnect.teacher@example.com');
    TeacherCalendarBusyInterval::query()->create([
        'user_id' => $teacher->id,
        'google_event_id' => 'cached-busy-event',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 200)]);

    app(GoogleCalendarService::class)->disconnect($teacher);

    expect($teacher->teacherCalendar()->exists())->toBeFalse()
        ->and(TeacherCalendarBusyInterval::query()->where('user_id', $teacher->id)->exists())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request->method() === 'POST');
});

test('reconnecting a Google Calendar invalidates the previous sync snapshot until a new sync succeeds', function () {
    $teacher = createCalendarTeacher('Reconnect Teacher', 'reconnect.teacher@example.com');
    addTeacherWindow($teacher, '2026-11-02');
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ], 200),
        'https://www.googleapis.com/calendar/v3/calendars/primary' => Http::response(['id' => 'reconnect.teacher@example.com'], 200),
    ]);

    $calendar = app(GoogleCalendarService::class)->connect($teacher, 'authorization-code');

    expect($calendar->status)->toBe('connected')
        ->and($calendar->last_synced_at)->toBeNull()
        ->and($calendar->calendar_id)->toBe('reconnect.teacher@example.com')
        ->and(app(AvailabilityService::class)->slotsForDate('2026-11-02'))->toBeEmpty();
});

test('malformed Google Calendar snapshots fail closed without clearing the last known Busy intervals', function () {
    $teacher = createCalendarTeacher('Malformed Snapshot Teacher', 'malformed.snapshot@example.com');
    TeacherCalendarBusyInterval::query()->create([
        'user_id' => $teacher->id,
        'google_event_id' => 'last-known-busy-event',
        'starts_at' => CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-02 09:30:00', 'Europe/Bucharest')->utc(),
    ]);
    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['kind' => 'calendar#events'], 200),
    ]);

    expect(fn () => app(GoogleCalendarService::class)->sync($teacher->teacherCalendar))->toThrow(RuntimeException::class);
    expect(TeacherCalendarBusyInterval::query()->where('google_event_id', 'last-known-busy-event')->exists())->toBeTrue()
        ->and($teacher->teacherCalendar->fresh()->status)->toBe('error');
});
