# Public website and booking journey

## Public experience

The public site is available in Romanian and English. Romanian is the default; the language selector stores the visitor's choice in the session and links, labels, forms, validation copy, and policy pages use the selected locale. The landing page is responsive and includes the offer, session details, how it works, bundle prices, the weekly availability grid, cancellation summary, FAQ, and privacy/terms links.

## Availability and time handling

The landing page and booking form show seven days at once. The grid loads from `GET /availability?week=YYYY-MM-DD`; the response is server-generated from `AvailabilityService`, with the requested week interpreted in `Europe/Bucharest`. Only server-returned slots are shown. Every slot is 15 minutes, and the domain enforces the 15-minute buffer and current booking conflicts. The API rejects past weeks. Slot selection is not a reservation: the server revalidates availability when a booking is submitted.

## Booking fields and policy

The learner selects a timeslot first. If they are not authenticated, the selected slot is preserved through login or account creation and they are returned to the booking form. New accounts collect name, email, phone/WhatsApp number, English level (A1–C2), session goal, optional notes, and password; returning learners use their saved profile and can update the phone number. The booking form requires explicit acceptance of the privacy/cancellation information. Server-side validation is authoritative. The configured policy version is written to the booking by the domain service.

A paid-bundle booking requires a valid active entitlement, sufficient credits, matching customer contact details, and an appointment start before bundle expiry. The booking service holds the slot transactionally and the already-paid bundle booking is confirmed through the domain service. The confirmation URL is signed and contains only a public reference.

The standalone free introduction requires the learner to verify an email code/link and a six-digit WhatsApp OTP before a booking or slot hold is created. Successful contact verification is stored on the account for subsequent sessions. The challenge expires after 30 minutes, the email link and OTP are single-use, and invalid or unverified contacts never create a booking or slot hold. The same verification flow can be completed from bundle checkout to qualify for the included free credit; bundle purchase itself remains available without verification, and the free credit is granted only after both channels are verified and the shared free offer remains unused. Live SMTP delivery, the approved WhatsApp verification template, and end-to-end production delivery remain launch gates. Likewise, bundle purchase displays an explicit not-yet-enabled checkout state until the Stripe integration is configured. Neither flow pretends that a payment or verification succeeded.

## Cancellation and privacy disclosure

The booking form links to public privacy and cancellation pages and displays the current cancellation summary before submission. The policy version is recorded on successful booking. The privacy page is an implementation draft; controller identity, legal bases, final retention periods, and statutory rights workflow must be reviewed and completed before launch. Prices are tax-inclusive and sessions are 15 minutes.

## Authenticated dashboard

After login, the learner sees Overview, Calendar, and Progress views. Overview shows upcoming sessions, active bundles, and remaining credits. Calendar loads the same server-generated weekly availability and routes the selected slot back through the existing booking service. Progress shows feedback cards for vocabulary, pronunciation, fluency, grammar, confidence, and any other configured metric; the teacher records baseline, current, and potential scores plus feedback through the Filament Session Feedback resource. Free-introduction-only users see potential development, while bundle users see recorded development and potential.

## User states

- Availability loading, no times for a day, and load failure.
- Validation errors for customer details, policy acceptance, and stale/unavailable slots.
- Expired or exhausted bundle entitlement.
- Free introduction requiring email-link and WhatsApp OTP verification; incomplete, expired, or mismatched verification creates no booking.
- Checkout not yet enabled; no order or charge is created.
- Confirmed booking with a signed reference URL.

## Dependencies / launch gates

Stripe Checkout, transactional email, the WhatsApp Cloud API adapter/outbox, and the email-link/WhatsApp OTP verification flow are implemented. Live SMTP/domain authentication, Meta account/template approval (including the one-parameter `contact_verification` template), and live webhook/delivery verification must be configured before launch. See [verification integration documentation](../integrations/verification.md). The public site must not treat a browser redirect as payment proof or mark unverified contacts as verified. Before launch, complete those integrations and obtain legal review of the privacy and customer terms.
