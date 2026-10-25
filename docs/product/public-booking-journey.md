# Public website and booking journey

## Public experience

The public site is available in Romanian and English. Romanian is the default; the language selector stores the visitor's choice in the session and links, labels, forms, validation copy, and policy pages use the selected locale. The landing page is responsive and includes the offer, session details, how it works, bundle prices, the weekly availability grid, cancellation summary, FAQ, and privacy/terms links.

## Availability and time handling

The landing page and booking form show seven days at once. The grid loads from `GET /availability?week=YYYY-MM-DD`; the response is server-generated from `AvailabilityService`, with the requested week interpreted in `Europe/Bucharest`. Only server-returned slots are shown. Every slot is 15 minutes, and the domain enforces the 15-minute buffer and current booking conflicts. The API rejects past weeks. Slot selection is not a reservation: the server revalidates availability when a booking is submitted.

## Booking fields and policy

The form collects full name, email, phone/WhatsApp number, English level (A1–C2), session goal, optional notes, selected slot, and explicit acceptance of the privacy/cancellation information. Server-side validation is authoritative. The configured policy version is written to the booking by the domain service. Public booking is rate-limited and requires no learner account.

A paid-bundle booking requires a valid active entitlement, sufficient credits, matching customer contact details, and an appointment start before bundle expiry. The booking service holds the slot transactionally and the already-paid bundle booking is confirmed through the domain service. The confirmation URL is signed and contains only a public reference.

The standalone free introduction must not bypass the domain's verified-email-and-phone checks. Until the email and WhatsApp verification delivery workflows are completed in their integration phases, a free-introduction form submission returns a clear message and creates no booking. Likewise, bundle purchase displays an explicit not-yet-enabled checkout state until the Stripe integration is delivered. Neither flow pretends that a payment or verification succeeded.

## Cancellation and privacy disclosure

The booking form links to public privacy and cancellation pages and displays the current cancellation summary before submission. The policy version is recorded on successful booking. The privacy page is an implementation draft; controller identity, legal bases, final retention periods, and statutory rights workflow must be reviewed and completed before launch. Prices are tax-inclusive and sessions are 15 minutes.

## User states

- Availability loading, no times for a day, and load failure.
- Validation errors for customer details, policy acceptance, and stale/unavailable slots.
- Expired or exhausted bundle entitlement.
- Free introduction requiring verified email and phone; no booking is created until verification is available and successful.
- Checkout not yet enabled; no order or charge is created.
- Confirmed booking with a signed reference URL.

## Dependencies / launch gates

Stripe Checkout, transactional email, and the WhatsApp Cloud API adapter/outbox are implemented by their dedicated issues. Live SMTP/domain authentication, Meta account/template approval, and live webhook verification must be configured before launch; email/phone contact verification remains an explicit launch gate. The public site must not treat a browser redirect as payment proof or mark unverified contacts as verified. Before launch, complete those integrations and obtain legal review of the privacy and customer terms.
