# Teacher dashboard

## Entry and role behavior

Active teacher accounts are routed from the shared customer dashboard to the dedicated teacher workspace at /teacher/dashboard. The teacher routes require authentication, the teacher role, and an active teacher account. Inactive teachers are redirected from the shared dashboard to their existing availability page, where the inactive-account warning and schedule controls remain available.

A user who is both staff and teacher lands in the teacher workspace for teaching work. The workspace provides a separate link to the Filament staff panel at /admin. Staff authorization remains enforced by the Filament panel; the teacher link does not grant staff access.

## Workspace overview

The overview shows assigned sessions for the current day and the next scheduled sessions, a weekly workload summary, feedback tasks, weekly availability counts and upcoming exceptions, and Google Calendar connection freshness. Session dates and weekly boundaries use Europe/Bucharest. Weekly workload counts confirmed and completed assigned sessions and sums their stored duration minutes; it is a schedule summary, not a payroll or attendance calculation.

Feedback tasks are confirmed sessions whose end time has passed and which have no SessionFeedback records yet. The existing teacher feedback form and persistence are reused.

## Session list and learner data

The My sessions view supports upcoming, today, past, and all-date periods, plus status filtering and 15-item pagination. All queries are scoped by teacher_id on the server. A session card and detail page expose only the learner name, recorded English level, session goal, appointment date/time, duration, booking reference, and session status. Customer email, phone, account/security details, payment/entitlement information, private booking notes, and unrelated learner history are not exposed to teachers.

The detail page reuses the existing assigned-booking route and feedback workflow. Feedback remains available only after a confirmed session has ended. Call-link status is shown without inventing readiness; the join link is available only on the existing authorized teacher booking detail page when the current OpenWA event matches the assigned teacher, booking revision, and appointment times.

## Availability and calendar

The dashboard links to the existing teacher availability page for weekly rules, exceptions, and Google Calendar connection management. Calendar status is derived from the teacher's own connection and its freshness; access tokens, refresh tokens, event titles, and event descriptions are never displayed.

## Localization and responsive behavior

The teacher dashboard and session detail page support Romanian and English using the current session locale. Layouts adapt from narrow mobile screens to desktop. The existing availability management page and its workflows remain the source of truth and are not duplicated.
