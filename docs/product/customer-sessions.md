# Customer sessions

## Purpose and navigation

The authenticated customer dashboard exposes **My sessions** as a dedicated area. The area uses the canonical customer ownership scope from docs/architecture/customer-identity-and-record-ownership.md; it does not identify records by email when an explicit account owner exists.

The session list provides four filters:

- **Upcoming**: pending and confirmed bookings whose start time has not passed.
- **Past sessions**: completed bookings and confirmed bookings whose scheduled start time has passed.
- **Cancelled and missed**: cancelled, expired, no-show, and refund-pending bookings.
- **All sessions**: every booking owned by the customer.

Each view is paginated at ten bookings per page. Queries eager-load only the teacher's approved public profile fields and current OpenWA call-event data needed by the customer interface.

## Customer-visible details

Each session shows its local date/time in Europe/Bucharest, persisted duration, booking goal, current status, and assigned teacher when available. The expandable detail area includes the public booking reference and customer-provided additional notes. It does not expose customer email/phone snapshots, provider credentials, internal booking audit data, or staff-only information.

Teacher presentation is limited to the teacher's display name, public photo, and public biography. An unassigned session is labelled as awaiting teacher assignment.

## Statuses

Customer-visible status labels are translated in Romanian and English. The UI uses the persisted booking status; it does not infer completion from the scheduled end time. A past confirmed booking remains confirmed until the booking lifecycle records completion, cancellation, expiry, or no-show.

## Management actions

The session list links to the existing signed booking-management route. That route remains the source of truth for cancellation eligibility and cancellation-credit outcomes. A direct reschedule action is shown only when CancellationPolicyService::customerActions() permits it, and uses the existing signed reschedule route. The underlying booking service continues to enforce policy and slot availability at submission time.

Signed management URLs expire at the scheduled start plus one day, or seven days from generation for older sessions. They are generated server-side and are not stored on the booking.

## OpenWA call links

A customer join action is shown only for a future confirmed booking. The existing authenticated call-join endpoint remains responsible for verifying customer/teacher authorization, confirmed booking status, assigned teacher, current booking revision, scheduled event status, and the encrypted join URL. The dashboard never renders the provider join URL itself.

Pending, failed, and not-yet-created call events are represented using the existing call-link status partial. A missing call link does not change or imply a failed booking.

## Timezone and duration

All session timestamps are rendered in Europe/Bucharest. Duration always comes from bookings.duration_minutes; the dashboard must not assume a fixed 15-minute duration.

## Operational guidance

Staff should continue to use the existing Filament booking resource and staff runbook for operational booking changes. Customer-visible signed management links reuse the existing cancellation and rescheduling policies; no second booking or cancellation implementation is introduced.