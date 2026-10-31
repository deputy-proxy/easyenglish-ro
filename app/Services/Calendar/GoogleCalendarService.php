<?php

namespace App\Services\Calendar;

use App\Jobs\Calendar\SyncBookingCalendarEvent;
use App\Models\Booking;
use App\Models\TeacherCalendar;
use App\Models\TeacherCalendarBusyInterval;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GoogleCalendarService
{
    public function authorizationUrl(string $state): string
    {
        $redirectUri = $this->redirectUri();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function connect(User $teacher, string $authorizationCode): TeacherCalendar
    {
        $response = Http::asForm()->acceptJson()->post((string) config('services.google_calendar.token_uri'), [
            'code' => $authorizationCode,
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Google Calendar authorization could not be completed (HTTP '.$response->status().').');
        }

        $existing = $teacher->teacherCalendar;
        $calendar = TeacherCalendar::query()->updateOrCreate(
            ['user_id' => $teacher->id],
            [
                'calendar_id' => 'primary',
                'access_token' => (string) $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token') ?: $existing?->refresh_token,
                'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
                'status' => 'connected',
                'last_synced_at' => null,
                'last_sync_error' => null,
            ],
        );

        try {
            $profile = $this->authorizedRequest($calendar)->get($this->apiUrl('/calendars/primary'));
            if ($profile->successful() && is_string($profile->json('id'))) {
                $calendar->update([
                    'calendar_id' => $profile->json('id'),
                    'calendar_email' => $profile->json('id'),
                ]);
            }
        } catch (RuntimeException) {
            // Calendar event sync will surface any remaining authorization or API problem.
        }

        return $calendar->refresh();
    }

    public function disconnect(User $teacher): void
    {
        $hasOutstandingBookings = $teacher->bookingsAsTeacher()->whereIn('status', ['confirmed', 'refund_pending'])->where('starts_at', '>', now())->exists();
        $hasPendingEventRemoval = Booking::query()->where(function ($query) use ($teacher): void {
            $query->where(function ($query) use ($teacher): void {
                $query->where('google_calendar_user_id', $teacher->id)
                    ->whereNotNull('google_calendar_event_id')
                    ->where(function ($query): void {
                        $query->whereIn('status', ['cancelled', 'expired', 'failed', 'refunded', 'refund_pending'])
                            ->orWhere(function ($query): void {
                                $query->where('status', 'confirmed')->whereColumn('teacher_id', '!=', 'google_calendar_user_id');
                            });
                    });
            })->orWhere(function ($query) use ($teacher): void {
                $query->where('teacher_id', $teacher->id)
                    ->whereIn('status', ['cancelled', 'expired', 'failed', 'refunded'])
                    ->whereNotNull('confirmed_at')
                    ->where(function ($query): void {
                        $query->whereNull('calendar_synced_at')->orWhereNotNull('calendar_sync_error');
                    });
            });
        })->exists();
        if ($hasOutstandingBookings || $hasPendingEventRemoval) {
            throw ValidationException::withMessages(['calendar' => 'The calendar cannot be disconnected while future confirmed sessions or pending calendar-event updates depend on it. Complete or cancel those sessions and let synchronization finish first.']);
        }

        $calendar = $teacher->teacherCalendar;
        if ($calendar !== null) {
            try {
                Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $calendar->refresh_token ?: $calendar->access_token]);
            } catch (\Throwable) {
                // Local credentials are still deleted even if Google's revocation endpoint is unavailable.
            }
            $calendar->delete();
        }
        TeacherCalendarBusyInterval::query()->where('user_id', $teacher->id)->delete();
    }

    public function sync(TeacherCalendar $calendar): void
    {
        try {
            $timeMin = CarbonImmutable::now('UTC')->subDays(30)->toRfc3339String();
            $timeMax = CarbonImmutable::now('UTC')->addYears(2)->addDay()->toRfc3339String();
            $events = [];
            $pageToken = null;

            do {
                $response = $this->authorizedRequest($calendar)->get($this->apiUrl('/calendars/'.rawurlencode($calendar->calendar_id).'/events'), array_filter([
                    'timeMin' => $timeMin,
                    'timeMax' => $timeMax,
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'showDeleted' => 'true',
                    'maxResults' => 2500,
                    'pageToken' => $pageToken,
                ], static fn (mixed $value): bool => $value !== null));

                if (! $response->successful()) {
                    throw new RuntimeException('Google Calendar event sync failed (HTTP '.$response->status().').');
                }

                $payload = $response->json();
                $items = is_array($payload) && array_key_exists('items', $payload) ? $payload['items'] : null;
                if (! is_array($items)) {
                    throw new RuntimeException('Google Calendar returned an invalid events list.');
                }

                foreach ($items as $event) {
                    if (! is_array($event)) {
                        throw new RuntimeException('Google Calendar returned a malformed event.');
                    }

                    if (($event['status'] ?? null) === 'cancelled' || ($event['transparency'] ?? 'opaque') === 'transparent') {
                        continue;
                    }

                    if (! is_string($event['id'] ?? null) || $event['id'] === '') {
                        throw new RuntimeException('Google Calendar returned a Busy event without an ID.');
                    }

                    $events[$event['id']] = $this->normalizeBusyEvent($event);
                }

                $pageToken = $response->json('nextPageToken');
            } while (is_string($pageToken) && $pageToken !== '');

            DB::transaction(function () use ($calendar, $events): void {
                DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
                TeacherCalendarBusyInterval::query()->where('user_id', $calendar->user_id)->delete();
                foreach ($events as $eventId => $interval) {
                    TeacherCalendarBusyInterval::query()->create([
                        'user_id' => $calendar->user_id,
                        'google_event_id' => $eventId,
                        ...$interval,
                    ]);
                }
                $calendar->update([
                    'status' => 'connected',
                    'last_synced_at' => now(),
                    'last_sync_error' => null,
                ]);
            });
        } catch (\Throwable $exception) {
            $calendar->update([
                'status' => 'error',
                'last_sync_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            throw $exception;
        }
    }

    public function synchronizeBooking(Booking $booking): void
    {
        $booking->refresh()->load('teacher.teacherCalendar');
        $eventId = $booking->google_calendar_event_id;
        $storedCalendar = $booking->google_calendar_user_id !== null
            ? TeacherCalendar::query()->where('user_id', $booking->google_calendar_user_id)->first()
            : null;

        if (in_array($booking->status, ['confirmed', 'refund_pending', 'no_show'], true) && $booking->starts_at->lessThanOrEqualTo(now())) {
            $booking->update(['calendar_synced_at' => now(), 'calendar_sync_error' => null]);

            return;
        }

        if (in_array($booking->status, ['cancelled', 'expired', 'failed', 'refunded'], true)) {
            if ($eventId !== null) {
                if ($storedCalendar === null) {
                    throw new RuntimeException('The original teacher calendar is unavailable; the saved event could not be removed.');
                }
                $this->deleteEvent($storedCalendar, $eventId, $booking->google_calendar_id ?: $storedCalendar->calendar_id);
            }

            $booking->update([
                'google_calendar_user_id' => null,
                'google_calendar_id' => null,
                'google_calendar_event_id' => null,
                'calendar_synced_at' => now(),
                'calendar_sync_error' => null,
            ]);

            return;
        }

        $calendar = $booking->teacher?->teacherCalendar;
        if ($calendar === null) {
            throw new RuntimeException('The assigned teacher has no connected Google Calendar.');
        }

        if ($eventId !== null && ($booking->google_calendar_user_id !== $calendar->user_id || $booking->google_calendar_id !== $calendar->calendar_id)) {
            if ($storedCalendar === null) {
                throw new RuntimeException('The original teacher calendar is unavailable; the saved event could not be moved.');
            }
            $this->deleteEvent($storedCalendar, $eventId, $booking->google_calendar_id ?: $storedCalendar->calendar_id);
            $booking->update([
                'google_calendar_user_id' => null,
                'google_calendar_id' => null,
                'google_calendar_event_id' => null,
            ]);
            $eventId = null;
        }

        $eventId ??= substr(hash('sha256', 'easyenglish:'.$booking->public_reference), 0, 32);
        $startsAt = $booking->starts_at->utc();
        $endsAt = $booking->ends_at->utc();
        $event = [
            'summary' => 'EasyEnglish session - '.$booking->customer_name,
            'description' => implode("\n", array_filter([
                'Customer: '.$booking->customer_name,
                'Email: '.$booking->customer_email,
                'Phone: '.$booking->customer_phone,
                'English level: '.$booking->english_level,
                'Session goal: '.$booking->session_goal,
                $booking->notes ? 'Notes: '.$booking->notes : null,
                'EasyEnglish booking reference: '.$booking->public_reference,
            ])),
            'start' => ['dateTime' => $booking->starts_at->toIso8601String(), 'timeZone' => 'Europe/Bucharest'],
            'end' => ['dateTime' => $booking->ends_at->toIso8601String(), 'timeZone' => 'Europe/Bucharest'],
        ];

        $url = $this->apiUrl('/calendars/'.rawurlencode($calendar->calendar_id).'/events/'.rawurlencode($eventId));
        $response = $this->authorizedRequest($calendar)->patch($url, $event);
        if (in_array($response->status(), [404, 410], true)) {
            $response = $this->authorizedRequest($calendar)->post($this->apiUrl('/calendars/'.rawurlencode($calendar->calendar_id).'/events'), ['id' => $eventId, ...$event]);
        }
        if ($response->status() === 409) {
            $response = $this->authorizedRequest($calendar)->patch($url, $event);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Google Calendar event could not be saved (HTTP '.$response->status().').');
        }

        $result = DB::transaction(function () use ($booking, $calendar, $eventId, $startsAt, $endsAt): string {
            $current = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if (! in_array($current->status, ['confirmed', 'refund_pending', 'no_show'], true)) {
                return 'cancelled';
            }
            if ($current->teacher_id !== $calendar->user_id) {
                return 'reassigned';
            }
            if (! $current->starts_at->utc()->equalTo($startsAt) || ! $current->ends_at->utc()->equalTo($endsAt)) {
                return 'changed';
            }

            $current->update([
                'google_calendar_user_id' => $calendar->user_id,
                'google_calendar_id' => $calendar->calendar_id,
                'google_calendar_event_id' => $eventId,
                'calendar_synced_at' => now(),
                'calendar_sync_error' => null,
            ]);

            return 'saved';
        });

        if ($result === 'cancelled') {
            $this->deleteEvent($calendar, $eventId);
            Booking::query()->whereKey($booking->id)->where('status', '!=', 'confirmed')->update([
                'google_calendar_user_id' => null,
                'google_calendar_id' => null,
                'google_calendar_event_id' => null,
                'calendar_synced_at' => now(),
                'calendar_sync_error' => null,
            ]);
        } elseif ($result === 'reassigned') {
            $this->deleteEvent($calendar, $eventId);
            Booking::query()->whereKey($booking->id)
                ->where('google_calendar_user_id', $calendar->user_id)
                ->where('google_calendar_event_id', $eventId)
                ->update(['google_calendar_user_id' => null, 'google_calendar_id' => null, 'google_calendar_event_id' => null]);
            SyncBookingCalendarEvent::dispatch($booking->id)->afterCommit();
        } elseif ($result === 'changed') {
            SyncBookingCalendarEvent::dispatch($booking->id)->afterCommit();
        }
    }

    private function deleteEvent(TeacherCalendar $calendar, string $eventId, ?string $calendarId = null): void
    {
        $calendarId ??= $calendar->calendar_id;
        $response = $this->authorizedRequest($calendar)->delete($this->apiUrl('/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId)));
        if (! $response->successful() && $response->status() !== 404 && $response->status() !== 410) {
            throw new RuntimeException('Google Calendar event could not be deleted (HTTP '.$response->status().').');
        }
    }

    /** @param array<string, mixed> $event
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, is_all_day: bool}
     */
    private function normalizeBusyEvent(array $event): array
    {
        $start = $event['start'] ?? null;
        $end = $event['end'] ?? null;
        if (! is_array($start) || ! is_array($end)) {
            throw new RuntimeException('Google Calendar returned a Busy event without a time range.');
        }

        $allDay = isset($start['date'], $end['date']);
        if ($allDay) {
            $startsAt = CarbonImmutable::parse((string) $start['date'], 'Europe/Bucharest')->startOfDay();
            $endsAt = CarbonImmutable::parse((string) $end['date'], 'Europe/Bucharest')->startOfDay();
        } else {
            if (! is_string($start['dateTime'] ?? null) || ! is_string($end['dateTime'] ?? null)) {
                throw new RuntimeException('Google Calendar returned a Busy event with an invalid time range.');
            }
            $startTimezone = is_string($start['timeZone'] ?? null) ? $start['timeZone'] : 'UTC';
            $endTimezone = is_string($end['timeZone'] ?? null) ? $end['timeZone'] : $startTimezone;
            $startsAt = CarbonImmutable::parse($start['dateTime'], $startTimezone)->utc();
            $endsAt = CarbonImmutable::parse($end['dateTime'], $endTimezone)->utc();
        }

        if ($startsAt->greaterThanOrEqualTo($endsAt)) {
            throw new RuntimeException('Google Calendar returned a Busy event with a zero-length or negative time range.');
        }

        return ['starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(), 'is_all_day' => $allDay];
    }

    private function authorizedRequest(TeacherCalendar $calendar): PendingRequest
    {
        if ($calendar->token_expires_at === null || $calendar->token_expires_at->lessThanOrEqualTo(now()->addMinutes(2))) {
            $this->refreshAccessToken($calendar);
        }

        return Http::withToken($calendar->access_token)->acceptJson()->timeout(20);
    }

    private function refreshAccessToken(TeacherCalendar $calendar): void
    {
        if (! $calendar->refresh_token) {
            throw new RuntimeException('Google Calendar authorization has expired. Reconnect the calendar.');
        }

        $response = Http::asForm()->acceptJson()->post((string) config('services.google_calendar.token_uri'), [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $calendar->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Google Calendar access token could not be refreshed (HTTP '.$response->status().').');
        }

        $calendar->update([
            'access_token' => (string) $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            'refresh_token' => $response->json('refresh_token') ?: $calendar->refresh_token,
        ]);
    }

    private function redirectUri(): string
    {
        $configured = config('services.google_calendar.redirect_uri');

        return is_string($configured) && $configured !== '' ? $configured : route('teacher.google.callback');
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('services.google_calendar.api_base'), '/').$path;
    }
}
