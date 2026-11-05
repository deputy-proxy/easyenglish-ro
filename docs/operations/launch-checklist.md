# Production launch checklist

**Release gate:** do not enable public paid sales until every required item is checked or the owner has explicitly documented an accepted risk. A green CI run verifies code paths with test configuration; it does not prove live provider accounts, production credentials, legal approval, backups, or Hostinger runtime limits.

## 1. Product and customer disclosures

- [ ] Confirm the public Romanian and English landing pages, booking form, bundle purchase page, privacy notice, and terms against the approved copy.
- [ ] Verify only 15-minute sessions are offered and the 15-minute inter-session buffer is respected.
- [ ] Verify the standalone free introduction requires no purchase and is limited to one per person using verified email and phone; a no-show forfeits the offer. Test expired/reused email links, expired/reused OTPs, five incorrect OTP attempts, resend cooldown, and mismatched contact details.
- [ ] Verify the three tax-inclusive bundles: 156 RON for 1 free + 4 paid; 390 RON for 1 free + 10 paid; 780 RON for 1 free + 20 paid.
- [ ] Verify bundle activation only after confirmed payment and expiry three calendar months from activation in Europe/Bucharest.
- [ ] Configure Google Calendar OAuth and the exact production callback URL; verify teachers can connect, complete an initial sync, and are excluded when sync becomes stale.
- [ ] Backfill the legacy central availability to teacher schedules, activate the teacher roster, and enable `TEACHER_AVAILABILITY_ENABLED` only after the public union calendar and assignment checks pass.
- [ ] Update and obtain Meta approval for the fourth teacher-contact placeholder in appointment confirmation, reminder, and reschedule templates.
- [ ] Verify appointment start must precede bundle expiry, including a booking submitted before expiry for a post-expiry appointment.
- [ ] Verify cancellation/rescheduling terms and policy-version recording: at least 24 hours restores credit; 12 to under 24 hours allows one reschedule; under 12 hours and no-shows forfeit credit, subject to mandatory law.
- [ ] Verify refund disclosures and approved exception process; no statement should imply software rules override statutory rights.

## 2. Legal, privacy, and finance gates

- [ ] Fill in and verify controller legal name, address, privacy contact, and any DPO details that apply in `docs/legal/privacy-notice.md`.
- [ ] Obtain privacy/legal review of notice, legal bases, processors, transfers, rights-handling, cookie/session behavior, customer terms, withdrawal rights, cancellation/refund policy, and statutory exceptions.
- [ ] Approve retention periods for each category in `docs/legal/retention-policy.md`, including backups, logs, notification records, financial records, and audit evidence.
- [ ] Keep `DATA_RETENTION_ENABLED=false` until the schedule and lawful bases are approved; test dry-run output before any authorized write run.
- [ ] Have the Romanian accountant confirm actual tax/VAT status, prepaid-bundle accounting, free-session treatment, invoice/receipt requirements, RO e-Factura scope, refund/correction documents, and statutory financial-record retention.
- [ ] Test and document the approved invoice/receipt process against a real test transaction and refund; store document references against the purchase/payment, not each credit-funded appointment.
- [ ] Record reviewer, role, date, approved document versions, and unresolved blockers in the release record.

## 3. Production infrastructure and access

- [ ] Confirm production HTTPS, correct Hostinger web root pointing to Laravel `public/`, writable `storage/` and `bootstrap/cache/`, `APP_ENV=production`, `APP_DEBUG=false`, and a unique `APP_KEY`.
- [ ] Verify database credentials and least-privilege grants; run migrations only after backup and review.
- [ ] Verify `/up`, public assets, Romanian default locale, English switching, and `/admin` authorization with a provisioned staff account and an ordinary account/guest.
- [ ] Confirm `/register` is unavailable and provision the first staff account through `php artisan staff:provision EMAIL --name="Name"` from a trusted shell; no default password or public staff registration may exist.
- [ ] Verify HTTPS secure cookies, session configuration, CSRF, password reset, and enabled Fortify two-factor/passkey features.
- [ ] Verify secrets are configured outside source control, absent from logs, and rotated/revoked if exposed. Confirm `APP_DEBUG=false`, `.env` and repository metadata are not web-accessible, and the document root is `public/`.

## 4. Payments and booking integrity

- [ ] Configure Stripe live/test keys in the matching mode and the exact HTTPS webhook URL/signing secret; keep `STRIPE_CHECKOUT_ENABLED=false` until test-mode validation passes.
- [ ] Test each bundle checkout's authoritative amount/currency and metadata; confirm success redirect alone never activates credits.
- [ ] Test valid/invalid webhook signatures, duplicate event IDs, out-of-order events, asynchronous payment success/failure, checkout expiry, late success reconciliation, and amount mismatch.
- [ ] Test simultaneous booking attempts for overlapping slots and the 15-minute buffer, plus competing requests for the final credit.
- [ ] Test hold expiry racing with payment confirmation and verify no confirmed booking is accidentally released.
- [ ] Test customer cancellation at exact 24-hour and 12-hour boundaries, one-time reschedule, teacher cancellation/expiry extension, no-show, refund success/failure/ambiguous state, and repeated staff action.
- [ ] Confirm refund provider state and reconciliation before telling a customer a refund is complete; do not manually alter credit/payment rows to force a result.

## 5. Email and WhatsApp

- [ ] Configure SMTP host, port, encryption, credentials, verified sender, and SPF/DKIM/DMARC; test delivery to representative inbox providers. Do not use `MAIL_MAILER=log` for verification because the email link would be exposed in logs.
- [ ] Confirm transactional email locale persists from the booking/purchase and outbox retries are visible in Filament.
- [ ] Configure Meta business assets, production system-user token and minimum permissions, phone number, approved template names/languages, signed webhook, and status subscriptions. Approve `WHATSAPP_TEMPLATE_CONTACT_VERIFICATION` with exactly one body text parameter for the six-digit OTP in both Romanian and English.
- [ ] Verify separate explicit WhatsApp service-message consent, `STOP` opt-out, and that marketing consent is neither assumed nor requested by this service flow.
- [ ] Test live WhatsApp OTP delivery and phone verification on real devices, then test live delivery for purchase, free intro, appointment confirmation, reminders, cancellation, rescheduling, teacher cancellation, no-show, refund success/failure, and 14-day/3-day expiry reminders.
- [ ] Test provider rejection, timeout, duplicate/out-of-order status webhooks, retries, unconfigured credentials, and opt-out while a message is queued.
- [ ] Keep `WHATSAPP_ENABLED=false` until live delivery and opt-out verification pass; CI must continue to use fakes/mocks.

## 6. OpenWA scheduled-call service

- [ ] Confirm the dedicated Railway `openwa-poc` service is healthy on its latest deployment, with `/health/live` returning `200` and one replica only. The 5 GB `openwa-session-data` volume is mounted at `/data` in `europe-west4-drams3a`.
- [ ] Confirm the Docker entrypoint executes preflight once and then starts `node src/server.js`; deploy logs must include the listener-started line and no repeated preflight loop.
- [ ] Configure Railway volume backups: daily (6-day retention), weekly (27-day retention), and monthly (89-day retention). Confirm AES-256 at-rest encryption, understand that restores are limited to the same project/environment, preserve the matching session encryption key secret, and test a non-production restore. Add an independent encrypted copy if cross-project disaster recovery is required.
- [ ] Configure `OPENWA_SERVICE_TOKEN` as a Railway secret and the matching value as a Hostinger application secret. Verify missing/invalid token returns `503`/`401` and valid Bearer authentication works; never place the token in browser code or logs.
- [ ] Use the Railway public HTTPS domain from Hostinger. Do not use `*.railway.internal` from Hostinger; Railway private networking is scoped to services in the same Railway project/environment.
- [ ] Verify `/health/ready` returns `503` when unpaired/disconnected and `200` only after the dedicated account is authenticated. Verify `/health/live` remains `200` while safely unpaired.
- [ ] Keep `OPENWA_ENABLED=false`, `OPENWA_ALLOW_LIVE_TEST=false`, and `OPENWA_PAIRING_ENABLED=false` until #220 passes and explicit product/operations approval is recorded. Pairing UI must return `404` while disabled.
- [ ] During the approved #220 test only, enable pairing temporarily with Basic authentication, use the dedicated non-production account, then disable pairing UI and verify QR/status routes return `404`. Never pair a personal or primary business account.
- [ ] Verify `openwa:monitor` runs every minute through the Hostinger scheduler and alerts/recovery notifications reach staff for readiness loss, elevated provider-error rates, stale call-event operations, failed notification deliveries, and queue backlog. Verify terminal call-event failures produce staff alerts and booking audit records.
- [ ] Test restart/reconnect, logout/re-authentication, backup restore, emergency disconnect, and queue recovery with the dedicated test account as part of #220.
- [ ] Exercise rollout stages in order: internal test allowlist, staff-only pilot, limited-customer allowlist, then general. Verify `OPENWA_ROLLOUT_STAGE=off` stops new events while preserving existing links and allowing cancellation/reconciliation.
- [ ] Confirm customer and assigned teacher receive the same current join link only after provider readiness; confirm teacher-only details are never included in customer messages.
- [ ] Exercise the documented manual-call fallback when a link is unavailable 30 minutes before a session; record the outcome and ensure the booking remains valid.
- [ ] Approve retention/deletion rules for encrypted join links and provider event metadata. Verify cancellation clears join URLs and original destination payloads from the Node event store; do not enable automatic retention writes before legal/accounting approval.
- [ ] Record provider terms/compatibility review, risk acceptance, technical owner, operational owner, backup/restore evidence, and explicit GO/NO-GO approval.

## 7. Scheduler, queue, monitoring, and recovery

- [ ] Add the Hostinger scheduler cron using the actual account's absolute paths and PHP binary; confirm `php artisan schedule:list` matches expectations.
- [ ] Verify actual cron cadence and execution/time limits on the target Hostinger plan. Record date, measured cadence, timeout limits, command output, and safe worker batch sizes below. **Not yet verified by CI or local testing.**
- [ ] Verify `notifications:dispatch`, `bookings:expire-holds`, and `stripe:reconcile` execute at expected cadence and are safe to run more than once.
- [ ] Verify the bounded database queue worker consumes jobs, processes retries, and exits inside Hostinger's actual execution limit. Confirm `retry_after` exceeds worker timeout.
- [ ] Trigger and recover a failed email, failed WhatsApp delivery, stuck pending payment, ambiguous refund, failed queue job, and overdue scheduler run; ensure alerts/logs identify the issue without exposing secrets or unnecessary personal data.
- [ ] Take a database backup and perform a restore to a separate target; record recovery time and integrity checks.
- [ ] Confirm rollback steps, current known-good release, migration forward-fix strategy, and owner/operator access.

### Hostinger runtime verification record

- Date and operator: **pending**
- Hosting plan and PHP binary/path: **pending**
- Observed scheduler cadence: **pending**
- Observed maximum safe execution time: **pending**
- Queue-worker command and safe limits: **pending**
- Backup restore test date/result: **pending**
- Rollback test date/result: **pending**

## 8. Accessibility and content review

- [ ] Test keyboard-only navigation, visible focus, form labels, required-field/error announcements, contrast, reduced-motion behavior if applicable, and responsive layout at narrow mobile/tablet/desktop widths.
- [ ] Review the entire public journey in Romanian and English, including loading, empty availability, expired slot, validation, checkout failure, payment pending, and confirmation states.
- [ ] Confirm all displayed dates/times state or clearly imply Europe/Bucharest and avoid misleading availability claims.

## 9. CI and release record

- [ ] `composer ci:check` passes locally on the release commit.
- [ ] `npm ci && npm run build` passes locally on the release commit.
- [ ] GitHub Actions PHP and frontend jobs are green for the PR and resulting main commit.
- [ ] Release SHA, deployment time, operator, smoke-test results, and rollback reference are recorded.
- [ ] Every unchecked item is either completed or remains an explicit launch blocker. Do not declare production launch-ready solely because CI is green.
