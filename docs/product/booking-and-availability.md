# Booking and availability domain

## Scope and customer-facing contract

EasyEnglish uses the existing `User` model for learners, staff, and teachers. Teacher assignment and individual availability are internal concerns. The public booking journey remains unchanged: customers see one aggregated EasyEnglish calendar, select a date/time as before, and do not see teacher identities, IDs, profiles, or individual schedules before confirmation.

The browser may display slot options, but only `AvailabilityService` and `BookingService` decide whether a time can be held or confirmed. Filament is for explicitly provisioned staff; teachers use a scoped `/teacher/availability` area for their own schedule and Google Calendar connection.

## Product invariants

- Every appointment is exactly 15 minutes, followed by a 15-minute buffer. Candidate slots therefore occupy a 30-minute window.
- Scheduling and customer-facing times use `Europe/Bucharest`. Booking timestamps and calendar busy intervals are stored as UTC instants and converted at boundaries.
- Weekly rules use weekday numbering: Sunday `0`, Monday `1`, through Saturday `6`. Multiple intervals per day and overnight intervals are supported. Active intervals for the same owner cannot overlap, including across midnight.
- One-off exceptions are explicit UTC timestamps entered/displayed in `Europe/Bucharest`. A teacher's `availability_override` replaces recurring weekly working hours for the affected date/range; `blocked` always removes overlapping session times and takes precedence over an override. The legacy central schedule retains its existing behavior while the rollout flag is off.
- A slot hold lasts five minutes. It is separate from the three-calendar-month validity of a paid bundle.
- A booking is assigned to one teacher internally. Assignment is fair among eligible teachers using least-recently-assigned ordering and user ID as a deterministic tie-breaker.
- Only users with `is_teacher=true`, `teacher_active=true`, and a connected Google Calendar whose last successful sync is no more than ten minutes old can receive new assignments.
- Teacher availability is disabled by default with `TEACHER_AVAILABILITY_ENABLED=false`. When disabled, the existing centrally managed schedule remains authoritative. When enabled, only teacher-scoped schedules and fresh calendar data are authoritative; the legacy global schedule is retained until the operator explicitly completes the backfill/rollout.
- A Google Calendar API failure does not invalidate an otherwise valid EasyEnglish booking. Calendar event writes are asynchronous, idempotent, retried, and visible to staff.
- Teacher information is disclosed to the customer only after the booking is confirmed, through the signed confirmation page and existing email/WhatsApp channels.
- There is one shared free-session allowance per person, guarded using verified email and phone. Bundle definitions, entitlement activation, three-calendar-month validity, credit ledger, and refund rules remain unchanged.

## Availability calculation

When teacher availability is disabled, `AvailabilityService::slotsForDate()` uses the legacy global weekly rules and exceptions. When enabled, it calculates each active teacher's bookable slots from:

`individual availability = recurring/date-specific working time - Busy Google Calendar intervals - conflicting EasyEnglish bookings`

The public calendar is the **union (logical OR)** of individual teacher availability. A slot is returned once if at least one eligible teacher can fulfil the complete 15-minute session plus its 15-minute buffer. It is omitted only when no eligible teacher can fulfil it. The public response contains only dates, times, and the existing timezone/slot fields; it never serializes teacher records or counts.

Google Calendar events with `transparency=transparent` or `status=cancelled` do not block availability. Opaque Busy events, including all-day events and expanded recurring instances, block the interval plus the 15-minute buffer on either side. Event titles, descriptions, attendees, and other private calendar content are not stored in the busy-interval table. A full bounded reconciliation replaces the stored interval snapshot, so event edits and deletions are reflected on the next successful sync.

Existing EasyEnglish bookings are also blockers even before their Google Calendar events have synchronized. Pending bookings block only while `hold_expires_at` is in the future; confirmed bookings always block. Bookings without a teacher (legacy records) block all teachers during the migration period. A teacher-specific booking blocks only that teacher. Buffer intervals touch but do not overlap: a 09:00–09:15 session can be followed by a 09:30 session, not a 09:15 session.

A teacher calendar is considered fresh for ten minutes. Failed, disconnected, revoked, or stale calendars fail closed and contribute no public availability. The calendar snapshot covers the previous 30 days and the next two years; dates beyond that verified horizon are not bookable through teacher availability.

The booking service revalidates availability under the singleton `booking_mutexes` row before creating a hold and again before confirmation. It reserves one eligible teacher atomically with the booking. If the originally assigned teacher becomes unavailable during the hold, confirmation may move the booking to another eligible teacher; if none remain, confirmation is rejected and the hold follows the existing expiry/release path. The same service is used for customer and staff rescheduling.

## Google Calendar integration

Google Calendar access is implemented behind `GoogleCalendarService`. Teachers authorize their own calendar using OAuth and the `calendar.events` scope, which is required to read Busy/Free events and create/update/delete EasyEnglish events. Access and refresh tokens are encrypted at rest using Laravel encrypted casts and are hidden from model serialization. The app stores only normalized Busy intervals and the minimum provider identifiers required for synchronization.

The current integration uses periodic full reconciliation rather than push channels. The scheduler queues `teachers:sync-calendars` every five minutes; the queued sync job retries transient failures with backoff. `bookings:reconcile-calendar-events` also runs every five minutes to recover missed/failed event writes and detect event changes after rescheduling or reassignment. Each sync expands recurring events, processes all pages, includes cancellations, and atomically replaces the cached Busy intervals only after a complete successful fetch. `last_synced_at`, status, and a redacted/actionable error are stored per teacher. This full-snapshot timestamp is the sync state; Google push-channel renewal is deferred because the current Hostinger deployment has no dedicated public push receiver/channel-renewal worker.

Confirmed bookings create a deterministic Google event on the assigned teacher's calendar. The booking stores the event ID, calendar owner, and calendar ID. Reschedules update the event; cancellation deletes it. Reassignment removes the event from the former teacher's calendar before writing it to the new teacher's calendar. Calendar API failures do not roll back the booking; `SyncBookingCalendarEvent` retries and records terminal failures on the booking for Filament review. Administrators can retry failed event writes. Teachers cannot disconnect a calendar while future confirmed sessions or outstanding event removals depend on it.

## Booking and credit lifecycle

### Booking states

- `pending`: a five-minute slot hold exists. A paid credit, included free credit, or free-introduction claim is reserved transactionally.
- `confirmed`: the hold was confirmed before expiry. Credit reservation is committed and a free-introduction claim becomes used. A calendar-event job is queued when a teacher is assigned.
- `expired`: the hold expired before confirmation. Reserved credits are restored or the unconfirmed free claim is released.
- `cancelled`, `refund_pending`, `no_show`, `failed`, and `refunded` remain explicit lifecycle states. Customer cancellation/rescheduling, teacher cancellation, no-show, and refund transitions pass through the existing domain services; do not edit status directly in Filament.

`BookingService::confirm()` is idempotent for already-confirmed bookings, rejects expired holds, and rechecks teacher eligibility when teacher availability is enabled. `BookingService::expireHolds()` rechecks state under lock before releasing a hold. The `bookings:expire-holds` command is scheduled every minute and uses `withoutOverlapping()`.

Bundle entitlements start as `pending_payment`, with no usable credits. Verified payment activates the entitlement, grants paid credits, conditionally grants the included free credit, and sets expiry. The credit ledger records grants, reservations, consumption, and restoration with unique idempotency keys. Stripe Checkout and verified payment/refund reconciliation remain authoritative for payment state. Transactional email and WhatsApp delivery use the durable notification outbox.

## Database model

- `users`: `is_teacher` role marker and `teacher_active` assignment eligibility flag; staff authorization remains separate.
- `availability_rules` / `availability_exceptions`: nullable `user_id` scopes a schedule to a teacher; `NULL` preserves legacy central rules.
- `teacher_calendars`: one encrypted OAuth connection per teacher, calendar reference, status, last sync, and error.
- `teacher_calendar_busy_intervals`: provider event ID and UTC interval only; no event content.
- `bookings`: nullable `teacher_id`, assignment timestamp, Google calendar owner/calendar/event references, last sync timestamp, and event-sync error.
- `bundle_definitions`, `bundle_entitlements`, `free_session_claims`, `credit_transactions`, `payments`, `notification_deliveries`, `booking_audits`, and `booking_mutexes`: existing purchase, credit, notification, audit, and concurrency records.

## Filament and teacher operations

Administrators can create/edit/activate/deactivate teacher accounts, manage each teacher's weekly intervals and date exceptions, inspect Google Calendar connection status/last sync/errors, inspect assigned bookings and event-sync failures, and retry calendar synchronization. Teacher accounts with booking history cannot be deleted; accounts must be deactivated to preserve historical references. A connected calendar must be disconnected before an otherwise unused teacher account can be deleted. Generic booking/entitlement CRUD remains disabled; state transitions must pass through domain services.

Teachers can manage only their own weekly availability, date exceptions, and Google Calendar connection at `/teacher/availability`. The route requires authentication and `is_teacher`; it does not grant access to Filament or other teachers' data. Deactivation prevents future assignments without deleting historical bookings or audit data.

## Safe migration and rollout

1. Keep `TEACHER_AVAILABILITY_ENABLED=false` while provisioning the teacher roster and Google OAuth configuration.
2. Create teacher users in Filament and leave them inactive until schedules and calendars are ready.
3. Run `php artisan teachers:backfill-availability` to copy legacy global weekly rules and exceptions to teachers who do not already have a teacher-specific schedule. The command is idempotent for teachers with existing schedules and never deletes the original global records. Use `--teacher=ID` for a single teacher.
4. Have each teacher connect Google Calendar and complete an initial successful sync. Confirm `last_synced_at` is recent and no actionable error is shown.
5. Activate eligible teachers, verify the aggregated public calendar and assignment tests, then set `TEACHER_AVAILABILITY_ENABLED=true` and clear/rebuild cached configuration.
6. Keep the legacy global schedule intact until the rollout is stable and a separate reviewed cleanup is approved.

## Operational follow-up

- Ensure the Laravel scheduler and database-backed queue worker run on Hostinger. `teachers:sync-calendars` queues full reconciliations every five minutes; queued calendar jobs use retries/backoff.
- Configure the Google OAuth consent screen, Calendar API, client ID/secret, and exact redirect URI. No live Google credentials are required in CI.
- Before production activation, verify the production MySQL/MariaDB locking behavior, Google API quotas, OAuth revocation behavior, notification template approval, and the customer data shown in calendar events.
- Production SMTP, Meta account/template approval, live webhooks, contact verification, Romanian legal review, and tax/invoicing approval remain separate launch gates.
