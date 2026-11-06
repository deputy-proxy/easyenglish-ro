# Public website and booking journey

## Public experience

The public site is available in Romanian and English. Romanian is the default; the language selector stores the visitor's choice in the session and links, labels, forms, validation copy, and policy pages use the selected locale. The landing page is responsive and includes the offer, session details, how it works, bundle prices, the weekly availability grid, cancellation summary, FAQ, and privacy/terms links.

## Availability and time handling

The landing page and booking form show seven days at once. The grid loads from `GET /availability?week=YYYY-MM-DD`; the response is server-generated from `AvailabilityService`, with the requested week interpreted in `Europe/Bucharest`. Only server-returned slots are shown. Every slot is 15 minutes, and the domain enforces the 15-minute buffer and current booking conflicts. The API rejects past weeks. Slot selection is not a reservation: the server revalidates availability when a booking is submitted.

## Booking fields and policy

The learner selects a timeslot first. If they are not authenticated, the selected slot is preserved through login or account creation and they are returned to the booking form. New accounts collect name, email, phone/WhatsApp number, English level (A1–C2), session goal, optional notes, and password; returning learners use their saved profile and can update the phone number. The booking form requires explicit acceptance of the privacy/cancellation information. Server-side validation is authoritative. The configured policy version is written to the booking by the domain service.

### Single-page authentication and step transitions

The availability calendar opens the booking panel in place and writes the selected slot to the `slot` query parameter with RFC 3986 encoding. The panel updates all registration/login hidden slot fields from the same client-side selection. Closing the panel clears the query parameter. A reload or the existing Fortify two-factor flow can therefore restore the selected slot from the URL.

Registration (`POST /booking/register`) and password login (`POST /booking/login`) retain the guest and `throttle:10,1` middleware, accept CSRF-protected JSON requests, and return an authenticated-step payload containing `status`, `next_step`, `slot`, `locale`, a user-facing message, and the server-rendered authenticated booking form partial. Successful registration/login regenerates the session and does not redirect to the dashboard or away from the landing page. The partial contains a fresh CSRF token; the client refreshes its CSRF meta token after replacing the step content. The original form-post redirects remain available as a progressive fallback.

Validation errors return HTTP 422 and are rendered into an accessible `role=alert` region. Invalid credentials have a distinct authentication-failure response. HTTP 419, 429, 5xx, and network failures have visible `aria-live` status messages; the form is marked `aria-busy` and its submit button is disabled while a request is in flight. A second submission is ignored while the first is pending. The server's unique email constraint remains authoritative against duplicate account creation.

Accounts with two-factor authentication receive HTTP 409 with a redirect to the existing Fortify challenge. The intended URL preserves the selected slot and the session preserves the chosen locale. After the challenge, the learner returns to the landing-page booking panel, not the dashboard. Contact verification uses authenticated, CSRF-protected JSON endpoints from the same panel; the response replaces only the booking step and refreshes its CSRF token.

Authentication does not reserve the selected slot or decide free-session eligibility. The booking domain revalidates the slot and account eligibility on final submission. An expired/unavailable slot returns a field error and the learner can choose another time. A confirmed booking redirects to the authenticated dashboard with a confirmation status; login or registration alone never triggers that redirect. If the free offer has already been used, the learner follows the separate bundle-selection and checkout path.

A paid-bundle booking requires a valid active entitlement, sufficient credits, matching customer contact details, and an appointment start before bundle expiry. The booking service holds the slot transactionally and the already-paid bundle booking is confirmed through the domain service. The confirmation URL is signed and contains only a public reference.

The standalone free introduction requires an authenticated learner to verify every channel required by the active mode: email and WhatsApp by default, or email only in `email_only` mode. A channel already verified on the account is skipped only when the current contact value matches. Email code/link and WhatsApp OTP confirmation update the account and the inline booking step; challenges expire after 30 minutes and each code/link is single-use. Invalid, expired, consumed, locked, throttled, delivery-failure, and changed-contact states are returned as localized JSON errors. If the learner changes an already-verified WhatsApp number, the booking action is hidden until the new number has consent and a successful OTP. After all required channels are verified, the server performs a read-only free-session eligibility check using normalized email OR phone. A claim is reusable only for a teacher-cancelled booking with no linked booking. The result reveals only eligible/already used and creates no booking, slot hold, teacher assignment, or free-session claim. The learner must explicitly submit the booking form, and the booking service rechecks eligibility transactionally before creating a hold/claim. The same verification flow can be completed from bundle checkout to qualify for the included free credit; bundle purchase itself remains available without claiming the standalone offer. Live SMTP delivery, the approved WhatsApp verification template, and end-to-end production delivery remain launch gates. Likewise, bundle purchase displays an explicit not-yet-enabled checkout state until the Stripe integration is configured. Neither flow pretends that a payment or verification succeeded.

When verification completes with `already_used`, the booking panel opens an accessible bundle-selection modal using the active `BundleDefinition` records. It displays each bundle's current tax-inclusive price (including an active discount), included session count, three-calendar-month validity, and links to the existing purchase/Stripe Checkout flow. Bundle names, prices, credits, and active status are read from the database; they are not duplicated in JavaScript. The selected appointment time is not reserved while the learner reviews or purchases a bundle. This branch creates no booking, slot hold, teacher assignment, free-session claim reservation, or OpenWA call event. The learner proceeds to the existing purchase page and checkout service. If checkout is cancelled or fails, no entitlement or successful payment is manufactured; the learner returns to the purchase page and can return to the landing-page calendar, where availability is loaded and validated again. A successful entitlement is activated only by verified Stripe payment processing/webhook reconciliation, never by the browser success URL.

## Free introductory conversation

The standalone free introduction is a 15-minute, teacher-guided conversation held on WhatsApp. The teacher uses the learner's stated English level, goals, and real-life use cases to guide the discussion and explain how future conversations can be adapted. It introduces the service; it is not a formal language assessment, written report, detailed action plan, or guarantee of a learning result. Teacher-recorded progress assessments are a separate workflow and must not be implied as a deliverable of the free introduction.

## Cancellation and privacy disclosure

The booking form links to public privacy and cancellation pages and displays the current cancellation summary before submission. The policy version is recorded on successful booking. The privacy page is an implementation draft; controller identity, legal bases, final retention periods, and statutory rights workflow must be reviewed and completed before launch. Prices are tax-inclusive and sessions are 15 minutes.

## Authenticated dashboard

After login, the learner sees Overview, Calendar, Progress, Sessions, Purchases, and My learning profile views. The profile area displays the learner’s current goal and preferences, and distinguishes a published teacher-assessed CEFR level from the learner’s self-reported level. Profile edits use the existing account settings page and do not rewrite booking-time snapshots. See [Learner profile](learner-profile.md) for source-of-truth and privacy rules. Overview shows upcoming sessions, active bundles, and remaining credits. Calendar loads the same server-generated weekly availability and routes the selected slot back through the existing booking service. Progress shows the five-dimension radar chart, complete and partial assessment history, a paginated archive of published progress reports, report detail, and next steps from persisted recommendations/feedback. The chart retains baseline/current/potential semantics; potential is not achieved progress. The teacher records current scores and overall feedback after a confirmed session, while authorized staff can manage baseline/current/potential values through the Filament Session Feedback resource. Draft reports remain private. See [Learner progress and assessments](learner-progress-and-assessments.md) for the complete lifecycle and visibility rules. Free-introduction-only users see potential development, while bundle users see recorded development and potential.

A confirmed booking redirects to the dashboard only after the booking transaction commits. The upcoming-session card displays the current OpenWA call-link state: not yet started, pending, ready, or failed. A ready link is opened through the authenticated `booking.call-join` route, which rechecks customer/assigned-teacher authorization and the latest booking revision before redirecting to WhatsApp. The join URL is not embedded in dashboard HTML or general booking serialization. Dashboard and join responses are `private, no-store` and use `Referrer-Policy: no-referrer`. Pending or failed call setup never rolls back the confirmed booking; a terminal failure creates a staff alert and the dashboard tells the learner the team will contact them. When OpenWA is disabled and no event exists, the dashboard does not claim a link is ready.

For bundle checkout, only a verified Stripe state activates the entitlement. A pending payment remains pending, a failed or cancelled checkout returns to the purchase page, and a verified success returns the signed-in learner to the dashboard. A browser return URL alone never grants credits.

## User states

- Availability loading, no times for a day, and load failure.
- Validation errors for customer details, policy acceptance, and stale/unavailable slots.
- Expired or exhausted bundle entitlement.
- Inline free-introduction contact verification, channel skipping for matching verified account contacts, and read-only email-OR-phone eligibility; incomplete, expired, mismatched, or already-used verification creates no booking or slot hold.
- Checkout not yet enabled; no order or charge is created.
- Confirmed booking redirected to the authenticated dashboard, with call-link state shown independently from booking status.
- Pending, ready, failed, and not-yet-started call-link states; only the current event revision can be opened.
- Bundle checkout cancelled, pending, failed, or webhook-confirmed; only webhook/provider reconciliation activates credits.

## Dependencies / launch gates

Stripe Checkout, transactional email, the WhatsApp Cloud API adapter/outbox, and the email-link/WhatsApp OTP verification flow are implemented. Live SMTP/domain authentication, Meta account/template approval (including the one-parameter `contact_verification` template), and live webhook/delivery verification must be configured before launch. See [verification integration documentation](../integrations/verification.md). The public site must not treat a browser redirect as payment proof or mark unverified contacts as verified. Before launch, complete those integrations and obtain legal review of the privacy and customer terms. The final assembled-system and real-device acceptance matrix is maintained in [public booking journey acceptance](../testing/public-booking-journey-acceptance.md); staging pairing, Android/iOS join tests, backup restore, and explicit product/operations GO remain mandatory before production rollout.

## Customer session management

After signing in, customers can open **My sessions** from the dashboard to review upcoming, completed, cancelled, expired, and no-show bookings. Session details show the booked duration, Bucharest-local date/time, session goal, assigned teacher's public profile, and booking reference. Eligible bookings link to the existing signed management and rescheduling flows. A WhatsApp join action is shown only when a current OpenWA event is ready for a future confirmed booking. See [Customer sessions](customer-sessions.md) for status, privacy, pagination, and timezone behavior.

## Customer purchase history

The authenticated dashboard's **My purchases** area shows bundle entitlement snapshots, payment attempts, refund/dispute status, and credit-ledger movements. It uses the persisted purchase amount and credit quantities rather than current catalog values. Receipts/invoices are explicitly unavailable until a real provider/accounting artifact is integrated. See [Customer purchases and payments](customer-purchases-and-payments.md).


The customer dashboard navigation and overview data sources are documented in [Customer dashboard overview](customer-dashboard-overview.md).
