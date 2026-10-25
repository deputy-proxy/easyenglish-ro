# Easy English

Easy English is an EdVenture product for adults who want to practise spoken English with an experienced teacher or conversation partner. The initial audience is working adults around A2–B2 who want practical speaking time, confidence, and focused practice.

This repository currently provides the Laravel application foundation, authentication/account-security scaffolding, Filament, and CI. The plan below describes the intended product implementation; it is not a claim that these product features already exist.

## Developer setup

Requirements: PHP 8.4+ with the extensions required by the locked Composer dependencies, Composer 2, Node.js 22/npm, and Git. Production uses MySQL/MariaDB; the default local setup uses SQLite and the log mailer, so no live SMTP, Stripe, or WhatsApp credentials are needed to develop or run CI.

```sh
git clone https://github.com/deputy-proxy/easyenglish-ro.git
cd easyenglish-ro
cp .env.example .env
composer setup
composer run dev
```

`composer setup` installs locked dependencies, creates the local SQLite database file when needed, generates the application key, runs migrations, and builds frontend assets. Run `composer ci:check` to execute the configured PHP lint, static analysis, and tests. CI also runs the frontend build. See the [Hostinger deployment and runtime runbook](docs/deployment/hostinger.md) for production configuration, deployment, cron, queue, backup, and troubleshooting guidance.

## Product goal

Make it easy for an adult learner to book and pay for a short, useful English-speaking session without having to create an account or navigate a complex course platform.

### MVP customer journey

1. Visit a bilingual Romanian/English landing page and understand the offer.
2. Choose an available 15-minute session in the Europe/Bucharest timezone, either as a free introduction or using a prepaid bundle.
3. Provide name, email, phone/WhatsApp number, English level, session goal, and optional notes.
4. For paid sessions, purchase a prepaid bundle in RON through Stripe Checkout. Prices include all applicable taxes.
5. Receive email and WhatsApp confirmation, including instructions for the session.
6. Join the teacher for a session through WhatsApp. The call itself is handled in the WhatsApp application; the WhatsApp Business Platform/API is for messaging, not for placing voice/video calls.
7. The teacher reviews upcoming bookings and the information needed to run each session in Filament.

The database is authoritative for availability and booking state. A browser redirect to Stripe must never, by itself, be treated as proof of payment. Verified Stripe webhooks confirm payment and trigger the paid-booking workflow.

## Confirmed MVP decisions

| Area | Decision |
| --- | --- |
| Teacher model | One teacher at launch; no teacher marketplace or teacher selection |
| Availability | Hybrid: recurring weekly availability plus manual adjustments and blocked dates |
| Session duration | 15 minutes only |
| Buffer | 15 minutes between sessions |
| Appointment-slot hold | 5-minute application-level hold for a pending slot reservation; separate from bundle validity |
| Free introduction | One 15-minute free introductory session per person, with verified email and phone; the offer is shared with the bundle's included free session |
| Bundle A | 5 total sessions (1 free + 4 paid): 156 RON, tax-inclusive |
| Bundle B | 11 total sessions (1 free + 10 paid): 390 RON, tax-inclusive |
| Bundle C | 21 total sessions (1 free + 20 paid): 780 RON, tax-inclusive |
| Bundle validity | Three calendar months from successful payment, using Europe/Bucharest; appointment must start before expiry |
| Tax display | Prices are in RON and include all applicable taxes |
| Bundle appointment cancelled at least 24 hours before | Restore the session credit; no automatic refund |
| Bundle appointment cancelled 12 to less than 24 hours before | One free reschedule; otherwise forfeit the credit |
| Bundle appointment cancelled less than 12 hours before | Forfeit the credit; no free reschedule |
| Teacher cancellation | Restore the credit and extend bundle validity when necessary; offer an extension or refund of affected unused credits if the customer cannot use them before expiry |
| Customer no-show | Forfeit the session credit; staff must record no-shows through an authorized, auditable action |
| Required booking data | Name, email, phone/WhatsApp, English level, session goal; notes field also included |
| Confirmation channels | Email and WhatsApp |
| WhatsApp integration | WhatsApp Business Platform/API; setup will be done collaboratively later |
| Session channel | WhatsApp call, arranged through the WhatsApp app |
| Website languages | Romanian and English |
| Hosting | Hostinger shared hosting |
| Payments | Stripe |
| Email delivery | Existing SMTP account/provider; sender identity and DNS authentication to be configured |
| Timezone | Europe/Bucharest as the scheduling timezone |
| Admin access | Explicitly authorized staff accounts only |
| Public website | Custom product landing page, visually aligned with EdVenture |

Cancellation deadlines are measured from the scheduled session start time. The bundle cancellation policy must be shown before purchase/booking and the accepted policy version recorded. A booking cancellation does not automatically refund a prepaid bundle. Refund eligibility, including statutory consumer rights, must be handled under the published terms and applicable law. Refund and rescheduling actions must be auditable.

## Product principles and constraints

- **Low friction:** no learner account or dashboard is required for the first release.
- **Short, focused sessions:** all sessions last 15 minutes.
- **Reliable booking:** prevent overlapping reservations, including the 15-minute buffer.
- **Payment integrity:** verify webhook signatures, process events idempotently, and reconcile payment state on the server.
- **Privacy:** collect only the learner information needed to arrange and deliver the session; protect it through authorization and a defined retention policy.
- **Operational simplicity:** use Filament for internal management rather than building a separate teacher portal in the MVP.
- **Bundle validity:** bundles activate on successful payment and remain valid for three calendar months in Europe/Bucharest. An appointment must start before expiry.
- **Progressive scope:** validate the booking and delivery workflow before adding subscriptions, a marketplace, AI features, or custom video infrastructure.

## Proposed domain model

Confirm names, relationships, and exact fields during implementation, but keep these concepts separate:

- **Bundle definition:** the server-configured product and its price, number of paid sessions, and included free-session allowance.
- **Bundle entitlement:** one customer's purchased bundle, payment state, activation/expiry, remaining credits, and audit history.
- **Credit ledger:** auditable credit grants, reservations, consumption, restorations, forfeitures, expiry, and refund adjustments.
- **Free-session eligibility:** verified contact details and the shared one-free-session-per-person allowance.
- **Availability rule:** the single teacher's recurring weekly availability.
- **Availability exception / blocked period:** one-off changes, time off, and manually blocked dates.
- **Available slot:** a concrete bookable start/end time, duration, and capacity. Generate/display slots from the rules and exceptions; the persisted booking state remains authoritative.
- **Booking:** selected 15-minute time, learner contact details, English level, session goal, optional notes, accepted policy version, status, and relevant timestamps; link a paid booking to the bundle credit it reserves.
- **Payment:** bundle purchase association, provider, Stripe Checkout Session/payment identifiers, amount/currency, tax-inclusive price, and payment status.
- **Notification / delivery record:** records email and WhatsApp confirmation/reminder attempts and outcomes without making notification delivery the source of truth for a booking.
- **Audit record:** important staff changes to bookings, refunds, rescheduling, and availability.

Do not build multi-teacher assignment or discovery for the MVP. Avoid introducing a generic course/enrolment system unless implementation demonstrates a real need.

## Implementation plan

### Phase 0 — Product rules

The product model is agreed: one standalone free 15-minute introduction, plus prepaid bundles of 1 free + 4 paid sessions for 156 RON, 1 free + 10 paid sessions for 390 RON, or 1 free + 20 paid sessions for 780 RON. All sessions last 15 minutes and prices include taxes. Bundle validity is three calendar months from successful payment. Operational setup and legal/compliance verification remain launch prerequisites.

**Exit criteria:** booking, payment, cancellation, rescheduling, and no-show rules are explicit enough to implement without relying on assumptions.

### Phase 1 — Establish the application baseline

- Review the current Laravel 13, Filament 5, Livewire, authentication, and CI setup before changing dependencies.
- Keep the existing authentication and security features unless a concrete product need justifies changes.
- Establish application conventions for domain services/actions, validation, policies, enums, and feature tests.
- Keep the current Hostinger shared-hosting deployment approach. Existing apps have already exercised the PHP/Composer stack, framework deployment, frontend assets, API connectivity, and database support on Hostinger.
- Use the Laravel scheduler via Hostinger cron and a database-backed queue processed by a cron-triggered worker using `queue:work --stop-when-empty`. Confirm actual cron cadence and execution limits when deploying this app.
- Configure local/test environment variables for database, SMTP, Stripe, and WhatsApp messaging without requiring live credentials in CI.
- Ensure CI covers formatting, static analysis, and automated tests.

**Exit criteria:** repeatable local setup, documented Hostinger scheduler/queue strategy, and green CI baseline.

### Phase 2 — Build the booking domain and admin workflow

- Add migrations, models, relationships, factories, and status definitions for availability rules, exceptions, bookings, payments, notifications, and audit records as needed.
- Implement recurring availability, manual overrides, blocked dates, and 15-minute inter-session buffers.
- Model bundle definitions, paid entitlements, credit ledger entries, free-session eligibility, and validity/expiry separately from appointment records.
- Enforce atomic credit reservation/restoration and prevent concurrent double-spending.
- Add Filament views/actions for bundle balances, expiry, and audited credit adjustments.
- Implement slot availability queries and server-side validation.
- Implement booking creation with a database transaction and appropriate locking/constraints so concurrent requests cannot reserve overlapping time.
- Make the distinction between bundle purchase and appointment booking explicit: buying a bundle does not automatically schedule every included session.
- Implement a 5-minute application-level hold for an appointment slot while the booking flow is being completed. Do not conflate this slot hold with the bundle's three-calendar-month validity. If checkout is associated with a held appointment, reconcile payment and appointment state safely before releasing the slot.
- Define explicit booking and payment state transitions. Keep pending, paid/confirmed, cancelled, expired, refunded, and failed states unambiguous.
- Add Filament management for availability rules, exceptions/blocked periods, bookings, and payment visibility where operationally useful.
- Apply authorization and validate every state-changing action server-side.

**Exit criteria:** staff can manage availability and inspect bookings; automated tests prove overlapping reservations cannot be created and an expired slot hold cannot release a slot that has become confirmed.

### Phase 3 — Build the public booking journey

- Create a responsive bilingual (Romanian/English) landing page describing the offer, intended audience, session lengths, process, and calls to action.
- Show the full week's available 15-minute slots in the Europe/Bucharest timezone, without requiring the visitor to select a day first.
- Collect the agreed learner details with server-side validation and clear privacy/cancellation information.
- For bundle purchases, create a pending purchase/checkout and activate credits only after verified Stripe payment. For free introductory bookings, do not create a zero-value checkout.
- Keep the 5-minute application-level hold for individual appointment-slot reservations only, not bundle validity.
- Offer the standalone free introductory session without checkout, plus the three tax-inclusive bundles: 156 RON for 1 free + 4 paid sessions; 390 RON for 1 free + 10 paid sessions; 780 RON for 1 free + 20 paid sessions.
- Make error, expired-slot, and unavailable-slot states clear and recoverable.
- Keep the public flow usable without learner registration.

**Exit criteria:** a learner can book the standalone free introduction without payment, or purchase a bundle and then book eligible sessions without relying on client-side availability or credit balances.

### Phase 4 — Integrate Stripe payments safely

- Create Stripe Checkout Sessions on the server using the selected bundle's authoritative price and RON currency; free introductory bookings do not use Stripe checkout.
- Store Stripe identifiers and correlate every payment with its booking.
- Verify webhook signatures and handle relevant success, failure, expiry, refund, and reconciliation events.
- Make webhook handling idempotent; repeated or out-of-order events must not create duplicate confirmations or invalid state transitions.
- Confirm a booking only after the server receives and validates authoritative payment confirmation.
- Keep bundle checkout/payment state separate from appointment-slot holds. Reconcile verified Stripe payment before activating bundle credits; release an expired slot hold only after checking the authoritative appointment state.
- Handle abandoned checkout and pending-booking expiry, releasing availability safely.
- Never trust the success URL, client-submitted amount, or browser state as payment proof.
- Keep a clear audit trail for refunds and cancellation-related payment changes.

**Exit criteria:** automated tests cover successful bundle payment, failed/expired checkout, duplicate webhook delivery, invalid signatures, entitlement activation races, refunds, and concurrent attempts to reserve overlapping time.

### Phase 5 — Confirmation and session delivery

- Send a booking confirmation after verified payment, including duration, date/time, timezone, and WhatsApp session instructions.
- Configure the chosen SMTP sender and test delivery failures.
- Set up the WhatsApp Business Platform/API collaboratively before production messaging is enabled. Document account prerequisites, credentials, templates, consent, delivery-status handling, and retries in a dedicated Markdown guide in the repository.
- Treat WhatsApp calling as a human-operated session in the WhatsApp application; do not scope automated voice/video calling through the messaging API.
- Give the teacher the learner's submitted level, goal, contact details, notes, and booking information in Filament.
- Record notification outcomes and allow safe retries without duplicating bookings or payments.
- Use the Laravel scheduler and cron-triggered database queue worker for reminders and retries, subject to tested Hostinger execution limits.

**Exit criteria:** a paid booking produces clear email and WhatsApp confirmations and an actionable teacher workflow, with failures visible to staff.

### Phase 6 — End-to-end quality and launch readiness

- Test the free introductory journey and each bundle purchase through Stripe test payment, credit activation, appointment booking, and email/WhatsApp confirmation.
- Test authorization, validation, privacy, booking concurrency, buffer rules, slot expiry, webhook idempotency, cancellation/refund handling, and notification retries.
- Verify accessibility, responsive layouts, bilingual content, loading states, empty states, and error handling.
- Add structured logging for payment/webhook failures without logging secrets or unnecessary personal data.
- Document Hostinger deployment, required environment variables, cron/scheduler setup, Stripe webhook setup, SMTP, WhatsApp configuration, backups, and operational procedures in Markdown files in the repository.
- Finalize the privacy notice, retention/deletion rules, and accountant-reviewed Romanian tax/invoicing workflow before launch.
- Run the complete CI suite and resolve failures before release.

**Exit criteria:** the end-to-end flow passes in a production-like environment and staff can operate the product without developer intervention for routine bookings.

## Core booking and payment rules

These rules should be treated as invariants and enforced on the server:

1. Availability displayed in the browser is informational; availability must be checked again when a booking is created.
2. A teacher's calendar cannot contain overlapping confirmed/pending reservations, including the 15-minute buffer.
3. Sessions and deadlines use the Europe/Bucharest scheduling timezone, with timezone-aware timestamps stored consistently.
4. A pending appointment-slot reservation has a 5-minute application-level hold. The hold is separate from bundle validity and must be released only after server-side checks establish that no confirmed appointment/payment state depends on it.
5. The database records the current booking/payment state; Stripe is the payment provider's authority for payment events.
6. A browser redirect is not proof of payment. A verified Stripe webhook (or a server-side reconciliation path) is required before marking a booking paid/confirmed.
7. Webhook and scheduled-job processing must be idempotent and safe to retry.
8. Amount, currency, booking reference, and Stripe identifiers must be validated server-side. Prices are configured server-side and include applicable taxes.
9. Notifications are downstream effects. A notification failure must not silently reverse a valid payment or create a duplicate booking.
10. Only authorized staff can manage availability or alter booking/payment state manually, and administrative changes should be auditable.
11. The cancellation policy shown before payment must be associated with the booking. Refunds and rescheduling actions must be authorized and auditable.
12. Customer-facing times must be clear and consistent with the Europe/Bucharest scheduling timezone.

## Explicitly out of scope for the first release

Unless product validation changes the priorities, defer:

- Learner accounts, learner dashboards, and self-service history
- Subscriptions and recurring billing
- A public marketplace or multi-teacher discovery/ranking experience
- AI conversation partners, AI assessment, and automated progress scoring
- Building custom video-call infrastructure
- Native mobile applications
- Complex course, cohort, or classroom management

## Testing strategy

- **Unit tests:** bundle pricing, credit ledger transitions, three-calendar-month validity, status transitions, hold/expiry rules, timezone handling, cancellation deadlines, and availability calculations.
- **Feature tests:** public bilingual booking flow, validation, authorization, and Filament operations.
- **Concurrency tests:** competing attempts to reserve overlapping times, including the 15-minute buffer.
- **Payment tests:** bundle Stripe Checkout creation, webhook signature verification, idempotency, expiry, refunds, credit activation, and payment/entitlement reconciliation.
- **Notification tests:** email/WhatsApp triggers, retry behaviour, scheduled reminders, and no duplicate customer confirmations.
- **End-to-end tests:** book the standalone free introduction; purchase each bundle; verify payment and credits; book multiple 15-minute sessions; test expiry, cancellation/restoration, rescheduling, teacher cancellation, no-show, refunds, and notifications.
- **Scheduler/queue tests:** verify scheduled tasks are safe to repeat, jobs can be processed by a cron-triggered worker, and failures are retried without duplicate side effects.

External services should be mocked or placed in test mode in automated CI. Do not require live payment or messaging credentials to run the normal test suite.

## GitHub development roadmap

The implementation is tracked through the following issues. Each issue includes scope, decisions, a step-by-step implementation plan, acceptance criteria, dependencies, and Markdown documentation deliverables.

1. [Phase 1 — Application baseline and Hostinger runtime workflow](https://github.com/deputy-proxy/easyenglish-ro/issues/3)
2. [Phase 2 — Availability, booking domain, and Filament administration](https://github.com/deputy-proxy/easyenglish-ro/issues/4)
3. [Phase 3 — Bilingual public landing page and booking journey](https://github.com/deputy-proxy/easyenglish-ro/issues/5)
4. [Phase 4 — Stripe Checkout, webhooks, and safe booking expiry](https://github.com/deputy-proxy/easyenglish-ro/issues/6)
5. [Phase 5 — Email confirmations and durable notification processing](https://github.com/deputy-proxy/easyenglish-ro/issues/7)
6. [Phase 6 — WhatsApp Business messaging and session instructions](https://github.com/deputy-proxy/easyenglish-ro/issues/8)
7. [Phase 7 — Cancellation, rescheduling, refunds, and audit](https://github.com/deputy-proxy/easyenglish-ro/issues/9)
8. [Phase 8 — Privacy, legal disclosures, and Romanian invoicing workflow](https://github.com/deputy-proxy/easyenglish-ro/issues/10)
9. [Phase 9 — End-to-end quality, deployment, and launch readiness](https://github.com/deputy-proxy/easyenglish-ro/issues/11)
10. [Cross-cutting — Security, staff access, and operational hardening](https://github.com/deputy-proxy/easyenglish-ro/issues/12)

Work in dependency order where practical: Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phases 5–7 → Phases 8–9. Legal/privacy drafts and integration setup documentation can be prepared in parallel, but relevant compliance and account configuration are launch gates. WhatsApp live setup will be completed collaboratively and must remain disabled in production until tested.

## Delivery order

Implement in the sequence above, with one reviewable pull request per coherent phase where practical. Keep each phase green in CI before building on it. Do not start optional features until the core booking → payment → confirmation path works reliably.

## Launch prerequisites

These are configuration, compliance, or operational tasks rather than unresolved product decisions:

1. **WhatsApp Business Platform/API:** configure the Meta business assets, number, credentials, approved templates where required, and consent/opt-out handling together before enabling production messaging.
2. **SMTP:** configure the sender address and credentials, and verify SPF, DKIM, and DMARC for the sending domain.
3. **Romanian tax and invoicing compliance:** have an accountant confirm the applicable obligations and configure an appropriate invoicing/record-keeping workflow. The advertised session prices include all applicable taxes; the business's actual tax treatment must still be confirmed.
4. **Privacy:** prepare and publish an appropriate privacy notice, confirm legal bases and data-subject workflows, and implement retention/deletion rules. Proposed default: delete or anonymize session goals/notes 12 months after the session unless a documented reason requires otherwise; retain financial records for the legally required period.
5. **Hostinger runtime limits:** verify cron frequency and execution limits on the target plan when deploying the app. The intended design is Laravel scheduler via cron and a database-backed queue processed by a cron-triggered `queue:work --stop-when-empty` command. Avoid requiring a permanently running worker.
6. **Production setup:** configure HTTPS, production environment variables, Stripe webhooks, backups, and operational monitoring before launch.

## External technical references

- The 5-minute application-level appointment-slot hold is independent of Stripe Checkout and the bundle's three-calendar-month validity. If a future flow ties checkout directly to a held appointment, verify current Stripe expiry constraints and implement server-side reconciliation rather than assuming Checkout can expire after five minutes.
