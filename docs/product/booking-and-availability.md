# Booking and availability domain

## Scope

The MVP has one teacher and no learner accounts. Availability and booking state are server-owned. The browser may display slot options, but only `AvailabilityService` and `BookingService` decide whether a time can be held. Filament is for explicitly provisioned staff only.

## Product invariants

- Every appointment is exactly 15 minutes.
- A 15-minute buffer follows each appointment. Candidate slots therefore occupy a 30-minute window, and the session plus buffer must fit inside a weekly availability window.
- Scheduling and customer-facing times use `Europe/Bucharest`. Booking timestamps are stored as UTC instants and converted for display. Date-only queries are interpreted in the scheduling timezone.
- Weekly rules use ISO-independent weekday numbering in the database: Sunday `0`, Monday `1`, through Saturday `6`. Windows can cross midnight when start and end differ and the end time is earlier than the start time.
- One-off exceptions are explicit UTC timestamps, entered/displayed in `Europe/Bucharest`. `availability_override` adds a window; `blocked` removes session times that overlap the block.
- A slot hold lasts five minutes. It is separate from the three-calendar-month validity of a paid bundle.
- There is one shared free-session allowance per person, guarded using verified email and phone. The free introduction is separate from paid bundle credits. A shared eligibility record ensures only one free allowance is granted across the standalone introduction and any bundle purchases; an included free credit is reserved/restored through that same record.
- Bundle definitions are seeded with the approved tax-inclusive prices: 156 RON for 4 paid sessions, 390 RON for 10 paid sessions, and 780 RON for 20 paid sessions. Each includes one free session only if the person has not already used or reserved the shared free introduction.
- A paid bundle is not usable until `BundleEntitlementService::activateAfterVerifiedPayment()` is called by a trusted payment-confirmation workflow. The included free credit is granted only after both contact channels are verified; `grantIncludedFreeCreditIfEligible()` is the domain hook for the later verification workflow. The service must not be called from a browser redirect or with unverified client payment data.
- Bundle validity starts at activation and ends three calendar months later, calculated without overflowing the end-of-month date in `Europe/Bucharest`. A session start must be strictly before expiry.

## Availability calculation

`AvailabilityService::slotsForDate()` combines active weekly rules with one-off overrides, then removes slots that overlap blocked periods or active bookings. It emits local timezone-aware start/end values. A session is excluded if its start is not in the future, its 15-minute session plus 15-minute buffer does not fit the availability window, a blocked period overlaps the session, or another confirmed/unexpired pending booking conflicts with the session-plus-buffer interval.

A pending booking blocks a slot only while `hold_expires_at` is in the future. Confirmed bookings always block it. Buffer intervals touch but do not overlap: a 09:00–09:15 session can be followed by a 09:30 session, not a 09:15 session.

The booking service revalidates availability inside a database transaction. It locks a singleton `booking_mutexes` row before checking capacity, so two application requests cannot both reserve the same otherwise-empty interval. The mutex also serializes free-session claims and credit reservations. On MySQL/MariaDB this uses row locking; SQLite's write-transaction serialization is used in local tests. Any future booking-writing code must use the service rather than inserting bookings directly.

## Booking and credit lifecycle

### Booking states

- `pending`: a five-minute slot hold exists. A paid credit, or an available included free credit, is reserved transactionally. A free-introduction claim is reserved against the verified contact details.
- `confirmed`: the hold was confirmed before expiry. A paid credit reservation is committed in the credit ledger. A free-introduction claim becomes used.
- `expired`: the hold expired before confirmation. The reserved bundle credit is restored, or the unconfirmed free claim is released so the person can retry.
- `cancelled`, `failed`, and `refunded` are reserved for explicit lifecycle workflows. Cancellation, rescheduling, no-show, and refund transitions must follow issue #9; do not edit status directly in Filament.

`BookingService::confirm()` is idempotent for already-confirmed bookings and rejects expired holds. `BookingService::expireHolds()` rechecks state under lock before releasing a hold, making repeated scheduler runs safe. The `bookings:expire-holds` command is scheduled every minute and uses `withoutOverlapping()`.

### Bundle entitlement lifecycle

A bundle entitlement starts as `pending_payment`, with no usable credits. After verified payment, the activation service checks the configured price/paid-credit count, activates the entitlement, grants paid credits, conditionally grants the included free credit, and sets expiry. The credit ledger records grants, reservations, consumption, and restoration with unique idempotency keys. A bundle with a previously used free introduction receives no second free credit. The shared eligibility record also prevents multiple bundles from each granting a separate included free session.

Payment statuses are explicit (`pending`, `succeeded`, `failed`, `expired`, `refunded`) and `PaymentStateService` guards legal transitions. Generic refund transitions are deliberately rejected until the dedicated refund workflow can reconcile credits, refund amounts, and statutory rights. The current booking domain does not integrate Stripe or send email/WhatsApp. Those provider workflows belong to later issues. The activation method is an internal domain boundary that later payment code must call only after signature-verified, authoritative confirmation.

## Database model

- `availability_rules`: recurring weekday windows.
- `availability_exceptions`: one-off added or blocked intervals, with creator attribution.
- `bundle_definitions`: fixed bundle catalog and server-side prices/credit counts.
- `bundle_entitlements`: customer purchase, payment activation state, credit balances, and expiry.
- `free_session_claims`: shared free-introduction eligibility/claim record.
- `bookings`: learner details, 15-minute appointment, accepted policy version, hold, and lifecycle state.
- `credit_transactions`: auditable credit ledger with type, quantity, balance-after, and idempotency key.
- `payments`: provider identifiers and RON payment state for the future Stripe workflow.
- `notification_deliveries`: durable email/WhatsApp delivery state for later notification workflows.
- `booking_audits`: booking, entitlement, availability, and staff action history. Avoid placing secrets or unnecessary personal data in metadata.
- `booking_mutexes`: a singleton row used to serialize booking-domain mutations.

## Filament operations and authorization

Staff can manage weekly availability and date exceptions, inspect bookings and learner details, confirm a valid held booking, and inspect bundle balances, expiry, payment records, the full credit ledger, and the audit log. Authorized staff can adjust paid credits only through the audited adjustment action, which requires a reason and refuses to create a negative balance. Free credits cannot be adjusted through this action because they share the one-per-person eligibility rule. Booking and entitlement records are not editable/deletable through generic CRUD forms because state changes must pass through domain services. Cancellation, rescheduling, no-show, and refunds are implemented with issue #9.

All authenticated users are not automatically staff. The `users.is_staff` flag must be explicitly provisioned; `User::canAccessPanel()` rejects ordinary accounts. To provision an existing trusted account, use a secure, authenticated server shell after verifying the intended account:

```sh
php artisan tinker
```

```php
$user = App\\Models\\User::where('email', 'verified-staff@example.com')->firstOrFail();
$user->forceFill(['is_staff' => true])->save();
```

Do not ship default credentials, expose a public staff-registration path, or allow ordinary profile updates to set `is_staff`.

## Operational follow-up

- Configure and verify the scheduler on Hostinger so `bookings:expire-holds` runs at the intended cadence.
- Stripe webhook verification, checkout reconciliation, payment-state transitions, notification retries, cancellation/refund policies, and production integration credentials are deliberately deferred to their dedicated issues.
- Before launch, verify concurrency on the production MySQL/MariaDB engine as well as the SQLite CI suite.
