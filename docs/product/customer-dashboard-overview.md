# Customer dashboard overview

## Primary information architecture

The authenticated dashboard is organized around five primary customer areas:

| Area | Existing route/state | Responsibility |
| --- | --- | --- |
| Overview | /dashboard or view=overview | Next session, usable credits, current level, progress highlight, recent activity, quick actions |
| My sessions | view=sessions | Upcoming, past, cancelled, and missed customer-owned bookings |
| My learning | view=profile and view=progress | Learner profile, assessed/self-reported level, five-dimension progress, assessments, reports, recommendations |
| My purchases | view=purchases | Customer-owned bundles, payment attempts, refunds, and credit activity |
| My account | /settings/profile | Existing account/contact, security, and learning-profile settings |

The My learning primary link opens the profile subview. Its secondary navigation switches between Profile and Progress and reports. The existing profile and progress query-string values remain valid.

The calendar is not a permanent primary navigation destination. **Book a session** is the prominent dashboard action and opens the existing calendar view (view=calendar). Direct links to view=calendar continue to work; the calendar page provides a Back to overview action.

My account reuses the existing settings route. No parallel account/settings implementation is introduced.

## Overview cards and source of truth

### Next session

The next session is the earliest future booking owned by the authenticated customer with status pending or confirmed. The card shows the actual booking start, duration_minutes, current booking status, and assigned teacher when present. If no teacher is assigned, the card says so rather than inventing one.

The booking details link is a signed booking-management URL generated from the owned booking. The current WhatsApp call-link state is shown for the next session and a compact list of the next four upcoming sessions. The existing current-call-event revision rules remain authoritative.

### Usable credits and bundles

Usable credits are calculated only from customer-owned entitlements where:

- status is active;
- expires_at is null or in the future; and
- credits_remaining or free_credits_remaining is greater than zero.

The displayed total is the sum of paid and free remaining credits. Pending-payment, expired, inactive-status, and exhausted entitlements do not contribute. The active-bundle count uses the same predicate.

The overview shows at most two recent usable bundle snapshots and the nearest finite expiry date. A non-expiring entitlement does not become the nearest expiry. Aggregate queries calculate total credits and count; a separate limited query fetches the two bundle summaries and the nearest-expiring entitlement. The calendar's existing bundle booking flow continues to use an eligible owned entitlement.

### Current English level

The current level follows the same source precedence as My learning:

1. latest published Progress Report with non-null overall_level on an owned booking;
2. otherwise the account's self-reported users.english_level;
3. otherwise a neutral not-yet-set state.

The source is labelled as published-report assessed or self-reported. The overview does not infer CEFR from numeric feedback scores.

### Progress highlight

The highlight is omitted unless a complete baseline and a positive strongest measured improvement exist. It uses the latest complete assessment and the existing five-dimension comparison semantics. Partial assessments, potential scores, incomplete baselines, and unrecorded estimates do not produce a highlight.

### Recent activity

Recent activity is assembled only from persisted records and their event timestamps:

| Event | Timestamp | Inclusion rule |
| --- | --- | --- |
| Session booking | bookings.created_at | Customer-owned booking |
| Payment confirmed | payments.confirmed_at | Payment belongs to a customer-owned entitlement and has a non-null confirmation timestamp |
| Progress report shared | progress_reports.shared_at | Report is shared and belongs to a customer-owned booking |

The controller fetches at most five of each event type, combines them by timestamp, and displays the six newest events. Pending/failed payments and unshared reports are not presented as completed events. Each event links to the relevant owned booking, purchases area, or published report detail. The activity list does not synthesize session-completion events from current status or duplicate events from the same source record.

## Customer, teacher, and staff boundaries

All customer dashboard records are scoped through the authenticated user's customer ownership:

- bookings and reports through Booking::ownedBy;
- entitlements and payments through BundleEntitlement::ownedBy;
- progress/assessment data through owned booking IDs.

A teacher's assignment to another learner's booking does not grant access to that learner's customer dashboard data. Staff/teacher accounts can only see customer records that belong to their own authenticated account; the teacher availability link remains a separate teacher route. Dual-role users may see their own customer records, never records belonging to learners they teach or administer.

The dashboard does not replace teacher routes or the Filament staff panel.

## Query bounds and responsiveness

- Upcoming bookings are limited to twenty; the overview displays the next session and at most four additional upcoming sessions.
- Usable bundle summaries are limited to two; credit totals and active count use aggregates; nearest expiry is a single-row query.
- Recent activity fetches at most five events per source type and displays at most six combined events.
- Progress chart reads are bounded to the earliest five baseline rows and the three latest complete assessments; complete/partial totals use scoped SQL aggregates.
- Assessment and report histories are paginated at ten entries per page and eager-load their related data.
- All customer-specific links and events are scoped to the authenticated customer before rendering.

The layout uses responsive Tailwind grids, wraps navigation on small screens, retains dark-mode styles, and exposes navigation landmarks and current-page state. The overview uses explicit empty states for no upcoming session, no usable bundle, no current level, and no recent activity. A progress highlight is omitted entirely when there is insufficient complete assessment data.

No analytics, tracking, or inferred activity events are introduced.


## Related product specifications

See the [dashboard integration overview](dashboard-integration.md) for the shared customer/teacher information architecture, role boundaries, shared data contracts, query-performance review, and release acceptance checklist.
