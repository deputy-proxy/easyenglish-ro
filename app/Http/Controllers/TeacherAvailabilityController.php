<?php

namespace App\Http\Controllers;

use App\Jobs\Calendar\SyncTeacherCalendar;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\User;
use App\Services\Calendar\GoogleCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TeacherAvailabilityController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $teacher */
        $teacher = $request->user();

        return view('teacher.availability', [
            'rules' => $teacher->availabilityRules()->orderBy('weekday')->orderBy('starts_at')->get(),
            'exceptions' => $teacher->availabilityExceptions()->orderBy('starts_at')->get(),
            'calendar' => $teacher->teacherCalendar,
            'weekdays' => [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'],
        ]);
    }

    public function updateAvailability(Request $request): RedirectResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();
        $rawAvailability = $request->input('availability', []);
        $intervals = [];
        if (is_array($rawAvailability)) {
            foreach ($rawAvailability as $interval) {
                if (is_array($interval) && filled($interval['starts_at'] ?? null) && filled($interval['ends_at'] ?? null)) {
                    $intervals[] = $interval;
                }
            }
        }
        $request->merge(['availability' => $intervals]);
        $validated = $request->validate([
            'availability' => ['present', 'array', 'max:70'],
            'availability.*.weekday' => ['required', 'integer', 'between:0,6'],
            'availability.*.starts_at' => ['required', 'date_format:H:i'],
            'availability.*.ends_at' => ['required', 'date_format:H:i'],
            'availability.*.is_active' => ['nullable', 'boolean'],
        ]);

        foreach ($validated['availability'] as $interval) {
            if ($interval['starts_at'] === $interval['ends_at']) {
                throw ValidationException::withMessages(['availability' => 'Each working interval must have a different start and end time.']);
            }
        }

        DB::transaction(function () use ($teacher, $validated): void {
            $teacher->availabilityRules()->get()->each(fn (AvailabilityRule $rule) => $rule->delete());
            foreach ($validated['availability'] as $interval) {
                AvailabilityRule::query()->create([
                    'user_id' => $teacher->id,
                    'weekday' => (int) $interval['weekday'],
                    'starts_at' => $interval['starts_at'],
                    'ends_at' => $interval['ends_at'],
                    'is_active' => (bool) ($interval['is_active'] ?? false),
                ]);
            }
        });

        return back()->with('status', 'Your weekly availability has been saved.');
    }

    public function storeException(Request $request): RedirectResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();
        $validated = $request->validate([
            'kind' => ['required', 'in:blocked,availability_override'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\\TH:i', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        AvailabilityException::query()->create([
            'user_id' => $teacher->id,
            'kind' => $validated['kind'],
            'starts_at' => CarbonImmutable::parse($validated['starts_at'], 'Europe/Bucharest')->utc(),
            'ends_at' => CarbonImmutable::parse($validated['ends_at'], 'Europe/Bucharest')->utc(),
            'reason' => $validated['reason'] ?? null,
            'created_by' => $teacher->id,
        ]);

        return back()->with('status', 'Your availability exception has been saved.');
    }

    public function destroyException(Request $request, AvailabilityException $exception): RedirectResponse
    {
        abort_unless($exception->user_id === $request->user()->id, 404);
        $exception->delete();

        return back()->with('status', 'The availability exception has been removed.');
    }

    public function connectGoogleCalendar(Request $request, GoogleCalendarService $calendar): RedirectResponse
    {
        abort_unless(config('services.google_calendar.client_id') && config('services.google_calendar.client_secret'), 503, 'Google Calendar is not configured.');
        $state = Str::random(48);
        $request->session()->put('google_calendar_oauth_state', $state);

        return redirect()->away($calendar->authorizationUrl($state));
    }

    public function googleCalendarCallback(Request $request, GoogleCalendarService $calendar): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_calendar_oauth_state');
        abort_unless(is_string($expectedState) && is_string($request->query('state')) && hash_equals($expectedState, $request->query('state')), 403);

        if ($request->filled('error')) {
            return redirect()->route('teacher.availability')->withErrors(['calendar' => 'Google Calendar authorization was not completed.']);
        }

        $validated = $request->validate(['code' => ['required', 'string', 'max:4096']]);
        try {
            $connection = $calendar->connect($request->user(), $validated['code']);
            SyncTeacherCalendar::dispatch($connection->id);
        } catch (\Throwable) {
            return redirect()->route('teacher.availability')->withErrors(['calendar' => 'Google Calendar could not be connected. Check the integration configuration and try again.']);
        }

        return redirect()->route('teacher.availability')->with('status', 'Google Calendar connected. Your first availability sync has been queued.');
    }

    public function disconnectGoogleCalendar(Request $request, GoogleCalendarService $calendar): RedirectResponse
    {
        $calendar->disconnect($request->user());

        return back()->with('status', 'Google Calendar disconnected.');
    }
}
