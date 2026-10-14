# Easy English

Easy English is an EdVenture product for adults who want to practise spoken English with an experienced teacher or conversation partner. The initial audience is working adults around A2–B2 who want practical speaking time, confidence, and focused practice.

This repository currently provides the Laravel application foundation, authentication/account-security scaffolding, Filament, and CI. The plan below describes the intended product implementation; it is not a claim that these product features already exist.

## Product goal

Make it easy for an adult learner to book and pay for a short, useful English-speaking session without having to create an account or navigate a complex course platform.

### MVP customer journey

1. Visit a bilingual Romanian/English landing page and understand the offer.
2. Choose an available 15-, 30-, or 45-minute session in the Europe/Bucharest timezone.
3. Provide name, email, phone/WhatsApp number, English level, session goal, and optional notes.
4. Pay in RON through Stripe Checkout.
5. Receive email and WhatsApp confirmation, including instructions for the session.
6. Join the teacher for a session through WhatsApp. The call itself is handled in the WhatsApp application; the WhatsApp Business Platform/API is for messaging, not for placing voice/video calls.
7. The teacher reviews upcoming bookings and the information needed to run each session in Filament.

The database is authoritative for availability and booking state. A browser redirect to Stripe must never, by itself, be treated as proof of payment. Verified Stripe webhooks confirm payment and trigger the paid-booking workflow.

## Confirmed MVP decisions

| Area | Decision |
| --- | --- |
| Teacher model | One teacher at launch; no teacher marketplace or teacher selection |
| Availability | Hybrid: recurring weekly availability plus manual adjustments and blocked dates |
| Session durations | 15, 30, and 45 minutes |
| Buffer | 15 minutes between sessions |
| Unpaid booking hold | Target of 5 minutes; implementation must account for Stripe Checkout's minimum configurable expiry of 30 minutes and safely expire open sessions through the API |
| Prices | 15 minutes: 39 RON; 30 minutes: 69 RON; 45-minute price still to be decided |
| Currency | RON |
| Cancellation | Full refund for cancellations at least 24 hours before the session; details for cancellations inside 24 hours still need confirmation |
| No-show | No refund |
| Required booking data | Name, email, phone/WhatsApp, English level, session goal; notes field also included |
| Confirmation channels | Email and WhatsApp |
| WhatsApp integration | WhatsApp Business Platform/API for automated messaging, subject to provider/account setup and template requirements |
| Session channel | WhatsApp call, arranged through the WhatsApp app |
| Website languages | Romanian and English |
| Hosting | Hostinger shared hosting |
| Payments | Stripe |
| Email delivery | Existing SMTP account/provider; credentials and sender identity to be configured |
| Timezone | Europe/Bucharest as the scheduling timezone |
| Admin access | Explicitly authorized staff accounts only |
| Public website | Custom product landing page, visually aligned with EdVenture |

## Product principles and constraints

- **Low friction:** no learner account or dashboard is required for the first release.
- **Short, focused sessions:** support 15-, 30-, and 45-minute formats.
- **Reliable booking:** prevent two customers from purchasing the same capacity-limited slot.
- **Payment integrity:** verify webhook signatures, process events idempotently, and reconcile payment state on the server.
- **Privacy:** collect only the learner information needed to arrange and deliver the session; protect it through authorization and an explicit retention policy.
- **Operational simplicity:** use Filament for internal management rather than building a separate teacher portal in the MVP.
- **Progressive scope:** validate the booking and delivery workflow before adding subscriptions, a marketplace, AI features, or custom video infrastructure.

## Proposed domain model

Confirm names, relationships, and exact fields during implementation, but keep these concepts separate:

- **Availability rule:** the single teacher's recurring weekly availability.
- **Availability exception / blocked period:** one-off changes, time off, and manually blocked dates.
- **Available slot:** a concrete bookable start/end time, duration, and capacity. Generate/display slots from the rules and exceptions; the persisted booking state remains authoritative.
- **Booking:** selected slot, learner contact details, English level, session goal, optional notes, status, and relevant timestamps.
- **Payment:** booking association, provider, Stripe Checkout Session/payment identifiers, amount/currency, and payment status.
- **Notification / delivery record (as needed):** records email and WhatsApp confirmation attempts and outcomes without making notification delivery the source of truth for a booking.

Do not build multi-teacher assignment or discovery for the MVP. Avoid introducing a generic course/enrolment system unless implementation demonstrates a real need.

## Implementation plan

### Phase 0 — Confirm the product rules

The core product direction is now agreed. Resolve the remaining items listed in **Open decisions and implementation constraints** before the related flows are considered complete.

**Exit criteria:** remaining rules are explicit enough to implement booking and payment state transitions without relying on assumptions.

### Phase 1 — Establish the application baseline

- Review the current Laravel 13, Filament 5, Livewire, authentication, and CI setup before changing dependencies.
- Keep the existing authentication and security features unless a concrete product need justifies changes.
- Establish application conventions for domain services/actions, validation, policies, enums, and feature tests.
- Verify the Hostinger shared-hosting plan supports the required PHP/Laravel version, document root configuration, HTTPS, environment configuration, database access, cron jobs, and deployment workflow.
- Configure local/test environment variables for database, SMTP, Stripe, and WhatsApp messaging.
- Decide how scheduled expiry/retry work will run on Hostinger. Prefer a reliable cron-triggered Laravel scheduler; do not assume a long-running queue worker is available on shared hosting.
- Ensure CI covers formatting, static analysis, and automated tests without requiring live credentials.

**Exit criteria:** repeatable local setup, hosting constraints verified, and green CI baseline.

### Phase 2 — Build the booking domain and admin workflow

- Add migrations, models, relationships, factories, and status definitions for availability rules, exceptions, slots/bookings, and payments.
- Implement recurring availability, manual overrides, blocked dates, and 15-minute inter-session buffers.
- Implement slot availability queries and server-side validation.
- Implement booking creation with a database transaction and appropriate locking/constraints so concurrent requests cannot oversell a slot.
- Implement a 5-minute application-level unpaid hold. Because Stripe Checkout's configurable session expiry cannot be set below 30 minutes, the app must safely expire an open Checkout Session through Stripe's API when the shorter hold elapses, and reconcile payment/session state before releasing capacity. Expiry and payment confirmation must be race-safe.
- Define explicit booking and payment state transitions. Keep pending, paid/confirmed, cancelled, expired, refunded, and failed states unambiguous.
- Add Filament management for availability rules, exceptions/blocked periods, bookings, and payment visibility where operationally useful.
- Apply authorization and validate every state-changing action server-side.

**Exit criteria:** staff can manage availability and inspect bookings; automated tests prove slot capacity cannot be exceeded under competing booking attempts and hold expiry cannot silently conflict with a successful payment.

### Phase 3 — Build the public booking journey

- Create a responsive bilingual (Romanian/English) landing page describing the offer, intended audience, session lengths, process, and calls to action.
- Show only genuinely available 15-, 30-, or 45-minute slots in the Europe/Bucharest timezone.
- Collect the agreed learner details with server-side validation and clear privacy information.
- Create a pending booking before redirecting the learner to Stripe, using the defined 5-minute application-level hold.
- Make error, expired-slot, and unavailable-slot states clear and recoverable.
- Keep the public flow usable without learner registration.

**Exit criteria:** a learner can move from the landing page to a valid pending booking and checkout without relying on client-side availability alone.

### Phase 4 — Integrate Stripe payments safely

- Create Stripe Checkout Sessions on the server using the booking's authoritative price and RON currency.
- Store Stripe identifiers and correlate every payment with its booking.
- Verify webhook signatures and handle relevant success, failure, expiry, and refund events.
- Make webhook handling idempotent; repeated or out-of-order events must not create duplicate confirmations or invalid state transitions.
- Confirm a booking only after the server receives and validates authoritative payment confirmation.
- Reconcile the 5-minute application hold with Stripe Checkout's longer minimum session expiry; expire the Stripe session via API when needed and never release a slot while payment state is ambiguous.
- Handle abandoned checkout and pending-booking expiry, releasing the slot safely.
- Never trust the success URL, client-submitted amount, or browser state as payment proof.

**Exit criteria:** automated tests cover successful payment, failed/expired checkout, duplicate webhook delivery, invalid signatures, hold expiry races, and concurrent attempts to book the same slot.

### Phase 5 — Confirmation and session delivery

- Send a booking confirmation after verified payment, including duration, date/time, timezone, and WhatsApp session instructions.
- Configure the chosen SMTP sender and test delivery failures.
- Integrate the WhatsApp Business Platform/API for approved transactional messages. Confirm provider/account setup, templates, recipient consent, and delivery-status handling before enabling production messages.
- Treat WhatsApp calling as a human-operated session in the WhatsApp application; do not scope automated voice/video calling through the messaging API.
- Give the teacher the learner's submitted level, goal, contact details, notes, and booking information in Filament.
- Record notification outcomes where needed and allow safe retries without duplicating bookings or payments.

**Exit criteria:** a paid booking produces clear email and WhatsApp confirmations and an actionable teacher workflow, with failures visible to staff.

### Phase 6 — End-to-end quality and launch readiness

- Test the full journey from slot selection through Stripe test payment and email/WhatsApp confirmation.
- Test authorization, validation, privacy, booking concurrency, buffer rules, slot expiry, webhook idempotency, and notification retries.
- Verify accessibility, responsive layouts, bilingual content, loading states, empty states, and error handling.
- Add structured logging for payment/webhook failures without logging secrets or unnecessary personal data.
- Document Hostinger deployment, required environment variables, cron/scheduler setup, Stripe webhook setup, SMTP, WhatsApp configuration, backups, and operational procedures.
- Run the complete CI suite and resolve failures before release.

**Exit criteria:** the end-to-end flow passes in a production-like environment and staff can operate the product without developer intervention for routine bookings.

## Core booking and payment rules

These rules should be treated as invariants and enforced on the server:

1. Availability displayed in the browser is informational; availability must be checked again when a booking is created.
2. A slot's capacity must not be exceeded, including when requests arrive concurrently.
3. Sessions must respect the 15-minute buffer and timezone rules.
4. An unpaid booking has a 5-minute application-level hold. Stripe Checkout expiry, API expiry requests, webhook delivery, and payment confirmation must be reconciled safely before capacity is released.
5. The database records the current booking/payment state; Stripe is the payment provider's authority for payment events.
6. A browser redirect is not proof of payment. A verified Stripe webhook (or a server-side reconciliation path) is required before marking a booking paid/confirmed.
7. Webhook processing must be idempotent and safe to retry.
8. Amount, currency, booking reference, and Stripe identifiers must be validated server-side.
9. Notifications are downstream effects. A notification failure must not silently reverse a valid payment or create a duplicate booking.
10. Only authorized staff can manage availability or alter booking state manually, and administrative changes should be auditable.
11. Customer-facing times must be clear and consistent with the Europe/Bucharest scheduling timezone.

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

- **Unit tests:** pricing calculations, status transitions, hold/expiry rules, timezone handling, and availability calculations.
- **Feature tests:** public bilingual booking flow, validation, authorization, and Filament operations.
- **Concurrency tests:** competing attempts to reserve the same capacity-limited slot, including the 15-minute buffer.
- **Payment tests:** Stripe Checkout creation, webhook signature verification, idempotency, expiry, and payment/booking reconciliation.
- **Notification tests:** email/WhatsApp triggers, retry behaviour, and no duplicate customer confirmations.
- **End-to-end test:** select a slot → submit learner details → complete Stripe test checkout → receive confirmation → see the confirmed booking in Filament.

External services should be mocked or placed in test mode in automated CI. Do not require live payment or messaging credentials to run the normal test suite.

## Delivery order

Implement in the sequence above, with one reviewable pull request per coherent phase where practical. Keep each phase green in CI before building on it. Do not start optional features until the core booking → payment → confirmation path works reliably.

## Open decisions and implementation constraints

The following items remain unresolved or need verification:

1. **45-minute price:** set the final price before enabling that duration in checkout.
2. **Cancellation inside 24 hours:** define whether this means no refund, a partial refund, or case-by-case handling; also define whether rescheduling is allowed.
3. **Tax and invoicing:** confirm the applicable tax treatment and whether/how receipts or invoices must be issued. Do not assume a tax setup.
4. **WhatsApp provider and production setup:** identify the WhatsApp Business Platform/API provider/account, complete business verification if required, approve message templates as applicable, and define consent and opt-out handling.
5. **Hostinger plan capabilities:** verify supported PHP version/extensions, Laravel deployment approach, cron availability/frequency, database, HTTPS, outbound connectivity, and whether queue workers can run persistently. Design around confirmed capabilities rather than assumptions.
6. **SMTP details:** confirm sender domain/address, SMTP credentials, and DNS authentication (SPF/DKIM/DMARC) before launch.
7. **Privacy and retention:** publish an appropriate privacy notice and define retention/deletion rules for booking details, notes, payment references, and notification logs.

## External technical references

- Stripe Checkout Session expiry: configurable expiry is between 30 minutes and 24 hours after creation. The 5-minute application hold therefore requires explicit server-side expiry/reconciliation rather than simply setting Checkout's `expires_at` to five minutes.
