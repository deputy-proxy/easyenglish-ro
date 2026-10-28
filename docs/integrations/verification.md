# Customer contact verification

## Purpose and policy

The standalone 15-minute free introductory session requires verification of both the customer's email address and WhatsApp phone number before a booking is created. This is a guest flow; customers do not need to create an account. Paid bundle checkout remains a separate path and does not require attending or claiming the free session first. The included free bundle credit is governed by the shared free-session eligibility rules.

Verification messages are transactional only. The WhatsApp service-message consent checkbox remains separate from marketing consent. A submitted contact value, a sent/queued message, or a provider's acceptance response is not proof of ownership.

## Email flow

1. The customer enters an email and phone number on the free booking form and requests verification.
2. The application creates a 30-minute challenge and emails a six-digit code plus a single-use backup link.
3. The email code is stored as an HMAC, expires with the challenge, and allows at most five incorrect attempts.
4. The code confirmation endpoint checks the session-bound challenge, expiry, code hash, and single-use state before setting `email_verified_at`. The signed backup link remains available if needed.
5. The customer returns to the form to finish the WhatsApp step.

## WhatsApp phone flow

1. The application sends a six-digit OTP using the configured WhatsApp Cloud API template `contact_verification`. Email delivery independently sends a separate six-digit OTP.
2. The challenge stores an HMAC of the OTP, not the code itself. The OTP expires with the 30-minute challenge.
3. A maximum of five incorrect attempts is allowed. The code is single-use. The request endpoint has an IP throttle, a per-contact hourly limit, and a 60-second resend cooldown.
4. Provider acceptance is not treated as verification; only a correct code submitted by the customer sets `phone_verified_at`.

## Booking enforcement

The booking submission validates the challenge ID, expiry, completion state, email, and normalized phone number on the server. The booking service independently requires both verification flags for the standalone free offer and serializes booking/claim mutations using the existing booking mutex. The existing shared free-session claim prevents the same email or phone from claiming the free offer more than once. No booking or slot hold is created when verification is missing, invalid, expired, or mismatched.

## Required configuration

- Configure a real transactional mailer. Do not use `MAIL_MAILER=log` or `MAIL_MAILER=array` for verification: log transports expose the full verification link in application logs, while array transports silently discard it. The guard also rejects a failover chain containing either non-delivering transport.
- Configure `WHATSAPP_ENABLED=true`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`, and the approved `WHATSAPP_TEMPLATE_CONTACT_VERIFICATION` template name.
- The WhatsApp template must be approved for the intended locale and accept one body text parameter containing the six-digit code. Configure `WHATSAPP_TEMPLATE_LANGUAGE_RO` and `WHATSAPP_TEMPLATE_LANGUAGE_EN` to match the actual approved template languages.
- Keep WhatsApp disabled until the Meta business assets, template approval, secrets, sender and live delivery tests are complete. Never put credentials in source control.
- Automated tests should fake the WhatsApp gateway and mail transport. CI must not require live provider credentials.

## Operations and privacy

Verification challenges expire after 30 minutes. Do not log tokens, OTPs, email bodies, or full verification URLs. Application logs may record the exception class and the fact that delivery failed, without contact values or secrets. Rate-limit failures should be presented generically. A delivery failure must not create a booking. The customer can request a new challenge after the cooldown; old challenge links and codes must remain unusable after expiry or successful confirmation. Email and phone codes each have a five-attempt limit.

## Launch checklist

- [ ] Configure and test the transactional email sender, including SPF, DKIM and DMARC for the sending domain.
- [ ] Approve and configure the WhatsApp verification template in Romanian and English.
- [ ] Confirm delivery and OTP entry on real devices, including expired codes, incorrect codes, resend cooldowns and provider outages.
- [ ] Confirm production mail and WhatsApp secrets are present without printing them in logs.
- [ ] Run the complete automated test suite and verify the free booking cannot be created before both checks pass.
