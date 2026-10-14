# Easy English

Easy English is an EdVenture product for adults who want to practise spoken English with an experienced teacher or conversation partner. The initial audience is working adults around A2–B2 who want practical speaking time, confidence, and focused practice.

This repository currently provides the Laravel application foundation, authentication/account-security scaffolding, Filament, and CI. The plan below describes the intended product implementation; it is not a claim that these product features already exist.

## Product goal

Make it easy for an adult learner to book and pay for a short, useful English-speaking session without having to create an account or navigate a complex course platform.

### MVP customer journey

1. Visit a public landing page and understand the offer.
2. Choose an available 15- or 30-minute session.
3. Provide contact details, approximate English level, and the goal for the session.
4. Pay through Stripe Checkout.
5. Receive booking confirmation and instructions, including a WhatsApp invitation or contact path.
6. The teacher can review upcoming bookings and the information needed to run each session.

The database is authoritative for availability and booking state. A browser redirect to Stripe must never, by itself, be treated as proof of payment. Verified Stripe webhooks confirm payment and trigger the paid-booking workflow.

## Product principles and constraints

- **Low friction:** no learner account or dashboard is required for the first release.
- **Short, focused sessions:** support 15- and 30-minute formats.
- **Reliable booking:** prevent two customers from purchasing the same capacity-limited slot.
- **Payment integrity:** verify webhook signatures, process events idempotently, and reconcile payment state on the server.
- **Privacy:** collect only the learner information needed to arrange and deliver the session; protect it through authorization and appropriate retention.
- **Operational simplicity:** use Filament for internal management rather than building a separate teacher portal in the MVP.
- **Progressive scope:** validate the booking and delivery workflow before adding subscriptions, a marketplace, AI features, or custom video infrastructure.

## Proposed domain model

Confirm names, relationships, and exact fields during implementation, but keep these concepts separate:

- **Teacher / conversation partner:** person delivering sessions, including public profile and active status.
- **Availability rule (optional recurring pattern):** a teacher's usual availability, if recurring availability is needed.
- **Available slot:** a bookable start/end time, duration, teacher, and capacity. The initial product should favour explicit, concrete slots if that keeps availability easier to reason about.
- **Booking:** selected slot, learner contact details, English level, session goal, status, and relevant timestamps.
- **Payment:** booking association, provider, Stripe Checkout Session/payment identifiers, amount/currency, and payment status.
- **Notification / delivery record (as needed):** records confirmation attempts and outcomes without making notification delivery the source of truth for a booking.

Avoid introducing a generic course/enrolment system unless the implementation demonstrates a real need for it. Keep the first version focused on one-to-one speaking sessions.

## Implementation plan

### Phase 0 — Confirm the product rules

- Define whether slots are teacher-specific, centrally managed, or both.
- Decide whether availability is created manually in Filament or generated from recurring rules.
- Define cancellation, rescheduling, refunds, late arrivals, and no-show rules.
- Confirm the final prices, currency, tax treatment, and whether prices differ by duration or teacher.
- Decide how the WhatsApp invitation is delivered: a generated link, a manual teacher workflow, or an approved messaging integration.
- Set the minimum required learner fields and the privacy/retention policy.

**Exit criteria:** written rules are clear enough to implement booking and payment state transitions without relying on assumptions.

### Phase 1 — Establish the application baseline

- Review the current Laravel 13, Filament 5, Livewire, authentication, and CI setup before changing dependencies.
- Keep the existing authentication and security features unless a concrete product need justifies changes.
- Establish application conventions for domain services/actions, validation, policies, enums, and feature tests.
- Configure environment variables and local/test configuration for database, mail, Stripe, and any messaging integration.
- Ensure CI covers formatting, static analysis, and automated tests.

**Exit criteria:** a repeatable local setup and green CI baseline.

### Phase 2 — Build the booking domain and admin workflow

- Add migrations, models, relationships, factories, and status definitions for teachers, slots, bookings, and payments.
- Implement slot availability queries and server-side validation.
- Implement booking creation with a database transaction and appropriate locking/constraints so concurrent requests cannot oversell a slot.
- Define explicit booking and payment state transitions. Keep pending, paid/confirmed, cancelled, expired, and failed states unambiguous.
- Add Filament management for teachers, slots, bookings, and payment visibility where operationally useful.
- Apply authorization and validate every state-changing action server-side.

**Exit criteria:** staff can create availability and inspect bookings; automated tests prove slot capacity cannot be exceeded under competing booking attempts.

### Phase 3 — Build the public booking journey

- Create a responsive landing page describing the offer, session lengths, intended audience, process, and clear calls to action.
- Show only genuinely available slots and support selecting a 15- or 30-minute session.
- Collect the learner's contact information, English level, and session goal with server-side validation.
- Create a pending booking before redirecting the learner to Stripe, using a safe expiry/hold policy for unpaid bookings.
- Make error, expired-slot, and unavailable-slot states clear and recoverable.
- Keep the public flow usable without learner registration.

**Exit criteria:** a learner can move from the landing page to a valid pending booking and checkout without relying on client-side availability alone.

### Phase 4 — Integrate Stripe payments safely

- Create Stripe Checkout Sessions on the server using the booking's authoritative price and currency.
- Store Stripe identifiers and correlate every payment with its booking.
- Verify webhook signatures and handle relevant success, failure, expiry, and refund events.
- Make webhook handling idempotent; repeated or out-of-order events must not create duplicate confirmations or invalid state transitions.
- Confirm a booking only after the server receives and validates authoritative payment confirmation.
- Handle abandoned checkout and pending-booking expiry, releasing the slot safely.
- Never trust the success URL, client-submitted amount, or browser state as payment proof.

**Exit criteria:** automated tests cover successful payment, failed/expired checkout, duplicate webhook delivery, invalid signatures, and concurrent attempts to book the same slot.

### Phase 5 — Confirmation and session delivery

- Send a booking confirmation after verified payment, including session details and the next steps.
- Configure email delivery and test delivery failures.
- Implement the agreed WhatsApp flow. If messaging automation is not selected for the MVP, provide a clear, reliable manual workflow and the appropriate WhatsApp link.
- Give the teacher the learner's submitted level, goal, contact details, and booking information in the internal view.
- Record notification outcomes where needed and allow safe retries without duplicating bookings or payments.

**Exit criteria:** a paid booking produces a clear customer confirmation and an actionable teacher workflow, with failures visible to staff.

### Phase 6 — End-to-end quality and launch readiness

- Test the full journey from slot selection through Stripe test payment and confirmation.
- Test authorization, validation, privacy, booking concurrency, slot expiry, webhook idempotency, and notification retries.
- Verify accessibility, responsive layouts, loading states, empty states, and error handling.
- Add structured logging for payment/webhook failures without logging secrets or unnecessary personal data.
- Document deployment, required environment variables, Stripe webhook setup, backups, and operational procedures.
- Run the complete CI suite and resolve failures before release.

**Exit criteria:** the end-to-end flow passes in a production-like environment and staff can operate the product without developer intervention for routine bookings.

## Core booking and payment rules

These rules should be treated as invariants and enforced on the server:

1. Availability displayed in the browser is informational; availability must be checked again when a booking is created.
2. A slot's capacity must not be exceeded, including when requests arrive concurrently.
3. A pending booking has a defined expiry/hold period. Expiry and payment confirmation must be race-safe.
4. The database records the current booking/payment state; Stripe is the payment provider's authority for payment events.
5. A browser redirect is not proof of payment. A verified Stripe webhook (or a server-side reconciliation path) is required before marking a booking paid/confirmed.
6. Webhook processing must be idempotent and safe to retry.
7. Amount, currency, booking ownership, and Stripe identifiers must be validated server-side.
8. Notifications are downstream effects. A notification failure must not silently reverse a valid payment or create a duplicate booking.
9. Only authorized staff can manage availability or alter booking state manually, and administrative changes should be auditable.

## Explicitly out of scope for the first release

Unless product validation changes the priorities, defer:

- Learner accounts, learner dashboards, and self-service history
- Subscriptions, packages, credits, and recurring billing
- A public marketplace or multi-teacher discovery/ranking experience
- AI conversation partners, AI assessment, and automated progress scoring
- Building custom video-call infrastructure
- Native mobile applications
- Complex course, cohort, or classroom management

## Testing strategy

- **Unit tests:** pricing calculations, status transitions, expiry rules, and availability calculations.
- **Feature tests:** public booking flow, validation, authorization, and Filament operations.
- **Concurrency tests:** competing attempts to reserve the same capacity-limited slot.
- **Payment tests:** Stripe Checkout creation, webhook signature verification, idempotency, and payment/booking reconciliation.
- **Notification tests:** correct triggers, retry behaviour, and no duplicate customer confirmations.
- **End-to-end test:** select a slot → submit learner details → complete Stripe test checkout → receive confirmation → see the confirmed booking in Filament.

External services should be mocked or placed in test mode in automated CI. Do not require live payment credentials to run the normal test suite.

## Delivery order

Implement in the sequence above, with one reviewable pull request per coherent phase where practical. Keep each phase green in CI before building on it. Do not start optional features until the core booking → payment → confirmation path works reliably.

## Decisions to record before implementation

- Final prices and currency
- Teacher/slot ownership and availability creation model
- Slot hold duration and handling of simultaneous payment/expiry events
- Cancellation, rescheduling, refund, and no-show policy
- Confirmation channels and WhatsApp integration approach
- Production hosting, mail delivery, and Stripe webhook endpoint configuration
