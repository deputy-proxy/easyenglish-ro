# Google Calendar integration runbook

## Prerequisites

1. Create or select a Google Cloud project owned by EasyEnglish.
2. Enable the Google Calendar API.
3. Configure the OAuth consent screen and add the intended teacher accounts as test users until the app is approved for production use.
4. Create an OAuth 2.0 Web application client. Register the exact callback URL shown by `route('teacher.google.callback')`, normally `https://<app-host>/teacher/google-calendar/callback`.
5. Configure the following environment variables:

```dotenv
GOOGLE_CALENDAR_CLIENT_ID=
GOOGLE_CALENDAR_CLIENT_SECRET=
GOOGLE_CALENDAR_REDIRECT_URI=https://<app-host>/teacher/google-calendar/callback
TEACHER_AVAILABILITY_ENABLED=false
```

The integration requests only `https://www.googleapis.com/auth/calendar.events`. This scope is needed to read event transparency/status and create, update, and delete EasyEnglish booking events. Tokens are encrypted at rest and excluded from model serialization/logging.

## Teacher setup

1. An administrator creates a teacher account in **Admin → Scheduling → Teachers** and sets it inactive until setup is complete.
2. The teacher signs in and opens `/teacher/availability`.
3. The teacher sets weekly working intervals and any date-specific unavailable periods or overrides (an override replaces that date's weekly schedule).
4. The teacher selects **Connect Google Calendar** and completes Google's OAuth consent flow.
5. The application queues an initial full sync. The teacher becomes eligible only after that job succeeds; the sync timestamp must be less than ten minutes old.
6. The administrator activates the teacher after confirming the schedule and calendar status.

Teachers can only manage their own schedule and connection. They cannot browse other teachers or see their bookings through the teacher portal. Administrators can inspect connection status, last sync, and error state in the Teachers resource.

## Synchronization policy

The scheduler queues `teachers:sync-calendars` and `bookings:reconcile-calendar-events` every five minutes. The latter detects future confirmed bookings with missing/stale event state and canceled bookings whose old events still need deletion. Each `SyncTeacherCalendar` job performs a full paginated snapshot of Google Calendar events from 30 days in the past through two years in the future, with recurring instances expanded by Google (`singleEvents=true`). A successful fetch replaces the teacher's cached Busy intervals in one database transaction while holding the booking mutex. A failed or partial fetch never clears the previous snapshot and marks the calendar as errored.

Busy means an event without `transparency=transparent` and with a non-cancelled status. Free events and cancelled events do not block availability. All-day events use local midnight boundaries in `Europe/Bucharest`; timed events are parsed from their supplied offsets and stored in UTC. Full reconciliation captures event edits, deletions, recurring-instance changes, and cancelled instances without retaining event titles, descriptions, attendees, or private content.

A calendar is trusted for availability only when its status is `connected` and its last successful sync is no more than ten minutes old. A disconnected, revoked, failed, or stale calendar contributes no availability. The current implementation uses periodic full snapshots and `last_synced_at` as its equivalent sync state; Google push channels and renewal are deferred until a dedicated public webhook receiver and channel-renewal worker are available.

## Booking event lifecycle

A confirmed booking queues an idempotent event write using a deterministic event ID. The event contains the customer name, email, phone, English level, session goal, optional notes, booking reference, and the 15-minute session time. It is written only to the assigned teacher's calendar. The booking stores the Google event ID, calendar owner, and calendar ID.

Rescheduling updates the existing event. If the assigned teacher changes, the previous calendar event is removed before the new teacher's event is written. Cancellation deletes the event. Calendar API failures do not invalidate the booking; jobs retry with backoff and terminal failures appear in the Booking resource. Administrators can use **Retry calendar event**. Teachers cannot disconnect a calendar while future confirmed bookings or pending event removals depend on it.

The confirmation email and signed confirmation page include the assigned teacher's name and contact details after confirmation. WhatsApp appointment confirmation/reminder/reschedule templates must include a fourth body placeholder for teacher contact details. Update and re-submit the following existing Meta templates before enabling teacher assignment with WhatsApp delivery:

- `WHATSAPP_TEMPLATE_INTRO_BOOKING_CONFIRMATION`
- `WHATSAPP_TEMPLATE_APPOINTMENT_CONFIRMATION`
- `WHATSAPP_TEMPLATE_APPOINTMENT_REMINDER_24H`
- `WHATSAPP_TEMPLATE_APPOINTMENT_REMINDER_1H`
- `WHATSAPP_TEMPLATE_BOOKING_RESCHEDULED`

The fourth placeholder is populated with teacher name, email, and phone (or `EasyEnglish team` when the legacy central schedule is active). Until Meta approves the updated templates, email remains the reliable confirmation channel and WhatsApp delivery failures remain visible in the notification outbox.

## Rollout from central availability

The feature is off by default. Keep it off while preparing teachers. Create accounts and run:

```sh
php artisan teachers:backfill-availability
```

The command copies global weekly rules and date exceptions to each teacher who has no teacher-specific schedule. It leaves the legacy rules untouched and skips teachers who already have any teacher-specific availability. Overnight weekly intervals are preserved during backfill. To backfill one teacher, use `--teacher=<user-id>`.

After each teacher has a successful calendar sync and is active, set `TEACHER_AVAILABILITY_ENABLED=true`, run `php artisan config:clear` (or rebuild the production config cache), and verify the public calendar. When disabled, the app continues using the original global availability calculation.

## Operations and troubleshooting

- Check **Admin → Scheduling → Teachers** for connection status, last successful sync, and sync error. Use **Sync calendar now** to queue a reconciliation.
- Check **Admin → Scheduling → Bookings** for assigned teacher, event reference, last event sync, and event-sync error. Use **Retry calendar event** after resolving the underlying API/auth issue.
- Check `php artisan queue:failed`, scheduler/cron history, and queue-worker logs. Do not retry repeatedly without fixing revoked credentials, redirect URI mismatch, missing scopes, Calendar API enablement, or quota errors.
- If Google revokes access, the calendar becomes ineligible after the next failed sync. Ask the teacher to reconnect; never treat stale cached time as free.
- Do not copy event titles/descriptions, OAuth tokens, refresh tokens, or customer notes into incident tickets. The cached Busy interval table intentionally contains only event IDs and UTC boundaries.
- Disconnect is blocked while future confirmed sessions or outstanding event removals depend on the connection. Cancel/reassign the bookings and allow event jobs to complete first.

## CI and test strategy

Google API calls are mocked in tests. CI does not require a live Google account or provider credentials. Tests cover Busy/Free/cancelled/all-day/recurring event normalization, stale-sync fail-closed behavior, teacher isolation, assignment fairness, booking revalidation, event idempotency, rescheduling, cancellation, and retry visibility.
