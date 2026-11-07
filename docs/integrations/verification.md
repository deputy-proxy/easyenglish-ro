# Customer contact verification

## Purpose and policy

The standalone 15-minute free introductory session requires an authenticated learner account and verification of every contact channel required by the active configuration before booking. The default mode requires both the account email address and WhatsApp phone number; `email_only` requires only the account email. Verification is completed in the landing-page booking panel. Paid bundle checkout remains a separate path and does not require claiming the standalone free offer first. The included free bundle credit is governed by the same shared free-session claim rules.

Verification messages are transactional only. The WhatsApp service-message consent checkbox remains separate from marketing consent. A submitted contact value, a sent/queued message, or a provider's acceptance response is not proof of ownership.

## Email flow

1. The learner signs in or creates an account, then enters or confirms the phone number in the booking panel. The email address is taken from the authenticated account and cannot be substituted in the verification request.
2. The application creates a 30-minute challenge and emails a six-digit code plus a single-use backup link only when the account email is not already verified.
3. The email code is stored as an HMAC, expires with the challenge, and allows at most five incorrect attempts.
4. The JSON confirmation endpoint checks the authenticated account, session-owned challenge, current contact values, expiry, code hash, and single-use state before setting `email_verified_at`. The signed backup link is also bound to the authenticated account and challenge in the same browser session.
5. The updated server-rendered booking step is returned in JSON; the learner stays on the landing page and proceeds to any remaining required channel.

## WhatsApp phone flow

1. The application sends a six-digit OTP through the globally selected WhatsApp provider only when the submitted phone is not already verified on the account. Meta uses the configured approved Authentication template `easyenglish_activation`; OpenWA renders the same application template key as a transactional text message. An already-verified phone is skipped only when its normalized value exactly matches the current submitted number.
2. The challenge stores an HMAC of the OTP, not the code itself. The OTP expires with the 30-minute challenge.
3. A maximum of five incorrect attempts is allowed. The code is single-use. The request endpoint has an IP throttle, per-account and per-contact hourly limits, and a 60-second resend cooldown.
4. Provider acceptance is not treated as verification; only a correct code submitted by the authenticated learner sets `phone_verified_at` and updates the account phone to the verified normalized value.

## Booking enforcement

All start/confirm endpoints require an authenticated session, CSRF protection, per-IP throttling, and a challenge ID stored in that session. The challenge email must match the authenticated account; confirmation also checks the current submitted phone against the challenge phone. A changed phone cannot reuse the previous challenge and must be verified again. Starting a replacement challenge invalidates any other active challenge for that account, including one issued to an earlier phone number. The email and phone channels already verified on the account are skipped only when their current values still match.

After the required channels are verified, the application performs a read-only eligibility lookup against `FreeSessionClaim`, matching normalized email **OR** normalized phone. A claim is reusable only when its status is `teacher_cancelled` and it has no booking, matching the existing `BookingService` exception. The response reveals only `eligible` or `already_used`; it does not disclose another account or claim. The lookup creates no booking, slot hold, teacher assignment, or claim.

The verification JSON endpoints return a server-rendered booking step and one of `verification_required`, `eligible`, or `already_used`. Error responses have explicit states for `invalid_code`, `expired`, `challenge_used`, `already_verified`, `attempts_locked`, `resend_wait`, `rate_limited`, `contact_changed`, and `delivery_failed`, with localized messages and field errors. The browser keeps the current step and selected slot in place. If a learner edits an already-verified WhatsApp number, the client hides the final booking action and requires consent plus a new OTP before continuing. The learner must explicitly submit the booking form after eligibility is confirmed. Final booking creation independently rechecks channel verification and the shared email-OR-phone claim inside the existing booking mutex/transaction, so a stale eligibility response cannot bypass a concurrent claim. No booking or slot hold is created when verification is missing, invalid, expired, mismatched, or the offer has already been claimed.

## Required configuration

- Configure a real transactional mailer. Do not use `MAIL_MAILER=log` or `MAIL_MAILER=array` for verification: log transports expose the full verification link in application logs, while array transports silently discard it. The guard also rejects a failover chain containing either non-delivering transport.
- Configure the selected provider with `WHATSAPP_PROVIDER=meta` and `WHATSAPP_ENABLED=true` plus Meta sender/token/template settings, or `WHATSAPP_PROVIDER=openwa` and `OPENWA_MESSAGING_ENABLED=true` plus the OpenWA HTTPS base URL, service token, and callback secret. Both providers should be configured and tested before relying on failover.
- The approved Meta Authentication template is `easyenglish_activation`, with Romanian locale `ro` and English locale `en`. It requires a body text parameter containing the six-digit code and a URL copy-code button parameter (`sub_type=url`, `index=0`) containing the same code. Configure `WHATSAPP_TEMPLATE_CONTACT_VERIFICATION=easyenglish_activation`, `WHATSAPP_TEMPLATE_CONTACT_VERIFICATION_LANGUAGE_RO=ro`, and `WHATSAPP_TEMPLATE_CONTACT_VERIFICATION_LANGUAGE_EN=en`.
- **Outstanding Meta-side mismatch:** both approved variants currently state that the code expires in 10 minutes and their copy-code URLs specify `code_expiration_minutes=10`, but the application challenge expires after 30 minutes. Edit both Authentication templates in WhatsApp Manager to reflect 30 minutes and wait for approval before enabling WhatsApp. The MCP template editor does not support Authentication edits; do not change the application's 30-minute challenge lifetime to hide this mismatch.
- Keep WhatsApp disabled until the Meta business assets, template approval, secrets, sender and live delivery tests are complete. Never put credentials in source control.
- Automated tests should fake the WhatsApp gateway and mail transport. CI must not require live provider credentials.

## Operations and privacy

Verification challenges expire after 30 minutes. Do not log tokens, OTPs, email bodies, or full verification URLs. Application logs may record the exception class and the fact that delivery failed, without contact values or secrets. Rate-limit failures should be presented generically. A delivery failure must not create a booking. The customer can request a new challenge after the cooldown; old challenge links and codes must remain unusable after expiry or successful confirmation. Email and phone codes each have a five-attempt limit.

## Launch checklist

- [ ] Configure and test the transactional email sender, including SPF, DKIM and DMARC for the sending domain.
- [ ] Approve and configure the WhatsApp verification template in Romanian and English.
- [ ] Confirm delivery and OTP entry on real devices, including expired codes, incorrect codes, resend cooldowns and provider outages.
- [ ] Confirm production mail and WhatsApp secrets are present without printing them in logs.
- [ ] Run the complete automated test suite and verify the free booking cannot be created before every required channel is verified.
- [ ] Verify the inline JSON flow stays on the landing page, skips only matching verified account contacts, and performs a read-only eligibility check.
- [ ] Verify eligibility checks create no booking, slot hold, teacher assignment, or free-session claim, and that final booking creation rechecks eligibility transactionally.
