# EasyEnglish production launch checklist

**Audit basis:** production hardening commit fbf2d8070f7079e3e131a16ea5d15e5322c4fee6 and live Hostinger verification on 2026-11-18.

**Release gate:** do not enable public paid sales until every required item is checked or an explicit, documented risk is accepted. Green CI proves the tested code paths; it does not prove production database contents, provider credentials, legal approval, delivery, backups, or Hostinger runtime behavior.

## Verified production snapshot

These are observations, not launch approvals:

- Hostinger website: easyenglish.ro is enabled; document root is /home/u583712040/domains/easyenglish.ro/public_html.
- The existing layout is intentional and must remain: the Laravel application, .env, vendor, artisan, and public/ are currently inside public_html. The root .htaccess routes requests to public/. Do not move the application to an easyenglish-app sibling directory.
- PHP web runtime: 8.4.27. Required MySQL, mbstring, intl, zip, and other Laravel extensions are available/enabled.
- SSL is active, HTTPS redirect is enabled, CDN is enabled, and Hostinger server cache is disabled.
- Dedicated MySQL database: u583712040_easyenglish_ro, approximately 6 MB, created 2026-11-16. Hostinger reports no remote database-access rules.
- Hostinger auto-deployment is operator-confirmed and successfully published commit `fbf2d8070f7079e3e131a16ea5d15e5322c4fee6` to the existing `public_html` application root on 2026-11-18. The root layout remains unchanged; `hostinger-deploy.sh` is not part of this path.
- EasyEnglish scheduler and database queue cron jobs were created on Hostinger on 2026-11-18. Both run every minute from /home/u583712040/domains/easyenglish.ro/public_html using /usr/bin/php. Successful execution and application-level processing still need runtime verification.
- The production database was confirmed fine by the operator; the initial database-inspection concern is no longer an outstanding blocker.
- [x] Hostinger PHP hardening applied and read back on 2026-11-18: expose_php=Off, log_errors=On, display_errors=Off, strict session mode=On, native session cookies Secure/HttpOnly/SameSite=Lax, memory_limit=512M, upload_max_filesize=32M, post_max_size=40M, max_execution_time=120, max_input_time=120. Laravel Set-Cookie headers were verified after deployment.
- Hostinger's file-list API confirms the deployed Laravel application tree and vendor dependencies exist. The operator has separately confirmed that the production database is fine.
- [x] Live HTTP probes after deployment returned 403/404 for `.env`, `.env.example`, `.git/config`, `.htaccess`, `.mcp.json`, `artisan`, Composer files, `phpunit.xml`, application/bootstrap/config/database/resources/routes/scripts/tests/vendor files, private storage paths, logs, and OpenWA PoC files. `/up`, homepage, contact, login, and a public teacher image returned 200.
- [x] Live login response after deployment set the Laravel session cookie with `Secure`, `HttpOnly`, and `SameSite=Lax`; the XSRF cookie was `Secure` and `SameSite=Lax` (not HttpOnly, as expected for the CSRF cookie).

## 1. Product and public website

- [ ] Confirm the production homepage matches the current Romanian-only product and approved copy. Do not require a separate English landing page or a public language switcher.
- [ ] Verify the public pages: homepage, contact, privacy notice, and terms/cancellations.
- [ ] Verify public teacher cards use the intended active teacher profiles, photos, and public details.
- [ ] Verify active FAQs and testimonials are accurate and approved.
- [ ] Test the contact form, spam controls, enquiry storage, and staff review workflow.
- [ ] Verify the customer journey reflects the current account-based flow: select a slot, register or log in, return to the dashboard, verify required contact channels, then explicitly complete the booking.
- [ ] Confirm the selected slot survives login, registration, and the Fortify two-factor challenge without being reserved prematurely.
- [ ] Verify the public site does not imply that a slot is reserved merely because a learner selected it.
- [ ] Review mobile, tablet, and desktop layouts, including calendar, teacher cards, bundle cards, forms, dashboard entry, and all error/empty/loading states.

## 2. Production database and operational data

**Operator confirmation (2026-11-18):** the production database is fine. Treat the database inspection concern from the initial audit as resolved; no additional production-row inspection is an outstanding launch blocker.

Continue to protect the database through routine operational controls:
- [ ] Take a fresh database backup before any production migration or data correction.
- [ ] Restore a backup to a separate target periodically and record the result.
- [ ] Keep production database credentials scoped to the EasyEnglish database and out of source control, logs, tickets, and chat.

## 3. Booking rules and free introduction

- [ ] Verify the current session duration is 15 minutes and the 15-minute inter-session buffer is enforced server-side.
- [ ] Verify the minimum booking notice is 24 hours, including the exact 24-hour boundary.
- [ ] Verify all scheduling calculations and displayed times use Europe/Bucharest.
- [ ] Test the seven-day availability grid, refresh behavior, disabled past/near-term slots, and empty availability state.
- [ ] Test the application-level five-minute slot hold, expiry, and recovery when a learner abandons the flow.
- [ ] Verify the learner must be authenticated to complete a booking.
- [ ] Verify the standalone free introduction requires the contact channels configured for the active verification mode. The documented default is email plus WhatsApp; email-only mode is a separate supported configuration.
- [ ] Verify email and phone verification are bound to the current contact values; test expired, reused, locked, throttled, mismatched, and delivery-failure challenges.
- [ ] Verify the one-free-introduction-per-person rule using normalized email OR phone, including concurrent attempts and previously used claims.
- [ ] Verify a free-session eligibility check does not itself create a booking, hold, claim, or teacher assignment.
- [ ] Test free-session booking, cancellation, teacher cancellation, and no-show outcomes.
- [ ] Verify the accepted policy version is stored against each booking.

## 4. Bundle catalog, discounts, vouchers, and credits

- [ ] Treat Filament → Payments → Lesson bundles and the production database as the source of truth for the current catalog. Do not copy old fixed bundle prices into this checklist or customer-facing documentation without checking the active rows.
- [ ] Verify every active bundle's displayed price, regular price, paid credits, and availability against its production record.
- [ ] Verify inactive bundles are not publicly purchasable.
- [ ] Verify only one catalog discount can be active at a time and discount date ranges are inclusive and non-overlapping.
- [ ] Test both percentage and fixed-RON discounts, including rounding, invalid values, start/end dates, and expiry.
- [ ] Test campaign vouchers, bundle restrictions, voucher reservations/redemptions, and the rule that a voucher overrides rather than stacks with a scheduled catalog discount.
- [ ] Verify the public homepage, inline bundle selection, purchase summary, and Stripe Checkout all show/use the same authoritative effective price.
- [ ] Verify stale displayed-price submissions are rejected and require the learner to review the current price.
- [ ] Verify purchase attempts preserve immutable price, regular-price, and discount snapshots; retries and webhook reconciliation must not recalculate an existing purchase from the current catalog.
- [ ] Verify a bundle purchase does not reserve an appointment slot or create a booking.
- [ ] Verify bundle validity and appointment-before-expiry rules against the current product policy and production configuration.
- [ ] Test credit reservation, consumption, restoration, forfeiture, expiry, and refund adjustments through the credit ledger. Do not repair balances by directly editing entitlement rows.

## 5. Payments, refunds, and accounting integration

- [ ] Verify the production Stripe account, mode, API credentials, and webhook signing secret without exposing secrets in logs or tickets.
- [ ] Confirm the exact production webhook URL and required event subscriptions.
- [ ] Keep checkout disabled until test-mode checkout, webhook signature verification, reconciliation, and legal/accounting approval pass.
- [ ] Test successful, failed, expired, asynchronous, duplicate, delayed, out-of-order, and amount-mismatched Stripe events.
- [ ] Verify a browser success redirect never activates an entitlement without authoritative server-side payment confirmation.
- [ ] Test payment reconciliation and recovery of stuck or ambiguous payments.
- [ ] Test full and partial refunds, failed refunds, ambiguous provider state, chargebacks/disputes, and repeated staff actions.
- [ ] Verify a refund is not reported as complete until Stripe confirms it.
- [ ] Confirm the invoice/receipt process for the actual live catalog and commercial model with the Romanian accountant.
- [ ] Confirm tax/VAT treatment, prepaid entitlement accounting, free-introduction treatment, statutory withdrawal rights, RO e-Factura scope, refund/correction documents, and record retention.
- [ ] Update README and tax/invoicing documentation after the live catalog has been verified; remove obsolete bundle descriptions and amounts.

## 6. Teacher scheduling and Google Calendar

- [ ] Verify the production value of TEACHER_AVAILABILITY_ENABLED. The example/default configuration is false; when disabled, the legacy central schedule remains the fallback.
- [ ] If teacher-based availability is the intended launch mode, verify the teacher roster and recurring schedules have been backfilled and reviewed before enabling it.
- [ ] Verify each launch teacher is active, has a valid schedule, and has completed an initial successful Google Calendar sync.
- [ ] Verify the exact production Google OAuth callback URL, consent configuration, credentials, and required Calendar API scope.
- [ ] Verify calendars with failed, revoked, or stale syncs are excluded from availability.
- [ ] Test Busy/Free event handling, recurring events, all-day events, timezones, edits, deletions, and partial-sync failures.
- [ ] Test booking event creation, rescheduling, teacher reassignment, and cancellation in the assigned teacher's calendar.
- [ ] Verify customers cannot choose or browse internal teacher allocation and teachers can access only their own workspace, availability, and assigned bookings.
- [ ] Test concurrent bookings and fair teacher assignment.

## 7. Email, Meta WhatsApp, and OpenWA

- [ ] Verify production SMTP configuration and sender identity. Confirm SPF, DKIM, and DMARC, then test delivery to representative inbox providers.
- [ ] Test all current transactional email types in Romanian, including booking, purchase, reminders, cancellation, rescheduling, teacher cancellation, no-show, refund, and expiry notifications.
- [ ] Verify email outbox retries, failure visibility, and duplicate prevention.
- [ ] Verify Meta production assets, phone number, system-user token, app secret, webhook verification token, signed callbacks, and approved templates.
- [ ] Verify META_WHATSAPP_VERIFICATION_ENABLED and test Meta OTP delivery on real devices. Confirm template code-expiry wording matches the application's challenge lifetime.
- [ ] Verify META_WHATSAPP_TRANSACTIONAL_ENABLED separately if Meta call-link-ready templates are used.
- [ ] Verify OPENWA_MESSAGING_ENABLED separately for ordinary customer/teacher transactional notifications.
- [ ] Verify OPENWA_ENABLED separately for scheduled call-event creation and reconciliation. Do not treat OpenWA messaging and call events as one feature flag.
- [ ] Verify explicit service-message consent, opt-out/STOP handling, and that marketing consent is not inferred.
- [ ] Test provider-specific routing, first-time recipients, timeouts, ambiguous outcomes, retries, duplicate/out-of-order callbacks, and opt-out while messages are queued.
- [ ] Verify no provider token or private message payload is exposed in browser code or logs.

## 8. OpenWA Railway service and call-event rollout

- [ ] Verify the current Railway service and latest deployment are healthy; test /health/live and /health/ready.
- [ ] Verify exactly one production replica and persistent session volume mounted at the documented path.
- [ ] Verify encrypted session storage, backup retention, session-key preservation, and a non-production restore test.
- [ ] Verify the Hostinger-to-Railway HTTPS URL and matching Bearer token; do not use Railway private DNS from Hostinger.
- [ ] Verify the pairing interface remains disabled outside an approved pairing window and that no personal/primary WhatsApp account is paired.
- [ ] Verify OPENWA_ROLLOUT_STAGE and the customer allowlist before any live call-event rollout.
- [ ] Test event creation, retrieval, cancellation, rescheduling, stale events, and idempotent recovery with the dedicated test account.
- [ ] Verify customer and assigned teacher receive the correct current join link only after provider readiness.
- [ ] Test the manual-call fallback and record how staff handle an unavailable call link.
- [ ] Verify monitoring alerts for disconnection, provider errors, stale events, failed notifications, and queue backlog.

## 9. Hosting, deployment, and application security

- [ ] Preserve the current Hostinger folder structure. Do not move the Laravel application out of public_html or create an easyenglish-app sibling directory as part of launch preparation.
- [x] Verify the hardened root .htaccess rewrite to public/ and test that .env, .git, artisan, Composer files, vendor/, app/, bootstrap/, config/, database/, private storage, and logs cannot be retrieved over HTTP. Public `/storage/` assets remain accessible; root `.well-known/` ACME pass-through was validated with a local Apache 2.4 integration test.
- [x] Verify active SSL and HTTP-to-HTTPS redirect: `http://easyenglish.ro/` returns 301 to `https://easyenglish.ro/`.
- [ ] Decide whether `www.easyenglish.ro` should redirect to the apex host; it currently serves HTTP 200 rather than redirecting.
- [ ] Verify APP_ENV=production, APP_DEBUG=false, a valid APP_KEY, the intended MySQL connection, and production-only provider credentials without displaying secret values.
- [x] Verify Laravel session response headers in production: the session cookie has Secure, HttpOnly, and SameSite=Lax. The XSRF cookie has Secure and SameSite=Lax; it is intentionally not HttpOnly.
- [x] Set PHP log_errors=On and expose_php=Off; read back both values on Hostinger.
- [x] Set PHP strict session mode and native session cookie defaults to Secure/HttpOnly/SameSite=Lax.
- [x] Set server-owned production `.env` values `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`, and `LOG_LEVEL=info`; a live application log event created `storage/logs/laravel-2026-11-18.log`. Verify old-file pruning after the 14-day retention window.
- [ ] Verify production error pages do not reveal stack traces, SQL, filesystem paths, or secrets.
- [x] Verify production HTML and static asset headers: HSTS on secure HTML responses and `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and `X-Frame-Options` on dynamic responses and static CSS/JS/public-storage assets. CSP remains deferred until report-only browser validation covers GTM, Stripe, Filament, and Livewire.
- [ ] Verify writable `storage/` and `bootstrap/cache/` permissions and file ownership. Hostinger confirms the `public/storage` symlink exists and its target serves public teacher images; ownership and mode bits remain unverified.
- [ ] Verify /up, public assets, admin authorization, teacher authorization, password reset, two-factor/passkey behavior, CSRF, and rate limits.
- [ ] Verify secrets are absent from Git, deployment archives, public responses, and logs.
- [x] Verify Hostinger auto-deployment publishes the intended `main` release into the existing `public_html` root; the hardening commit and its new middleware/headers were confirmed live.
- [x] Verify the latest auto-deployment preserved the server-owned `.env`, `storage/`, root `.htaccess`, and `public/storage` symlink. Root ACME challenge pass-through was validated in local Apache; no live challenge file was present to test.
- [ ] Confirm Hostinger ACME challenge files are preserved by future auto-deployments when present.
- [x] Record successful auto-deployment of `fbf2d8070f7079e3e131a16ea5d15e5322c4fee6` on 2026-11-18 and the post-deployment HTTP smoke tests. The `hostinger-deploy.sh` script is not required.

## 10. Scheduler, queues, logs, and backups

- [x] Create the EasyEnglish scheduler cron on Hostinger: every minute, from /home/u583712040/domains/easyenglish.ro/public_html, using /usr/bin/php artisan schedule:run.
- [x] Create the bounded database queue worker cron on Hostinger: every minute, using queue:work database --stop-when-empty --max-time=50 --max-jobs=100 --tries=3 --timeout=30.
- [x] Confirm the configured database queue retry_after is 90 seconds, greater than the worker's 30-second timeout.
- [ ] Verify both cron entries execute successfully and continue at the expected cadence; inspect Laravel logs, queue backlog, and failed jobs after runtime.
- [ ] Verify the scheduled commands in routes/console.php: booking hold expiry, Stripe reconciliation, notification dispatch, call-event dispatch, OpenWA monitoring, teacher calendar sync, calendar-event reconciliation, and verification challenge pruning.
- [ ] Verify worker runtime remains within Hostinger limits and that no queue backlog accumulates.
- [ ] Verify each scheduled command is safe to run repeatedly and uses without-overlapping locks as intended.
- [ ] Test failed email, failed WhatsApp delivery, stuck payment, ambiguous refund, failed job, stale calendar, OpenWA disconnect, and overdue scheduler recovery.
- [x] Verify Laravel daily logging is active by creating `storage/logs/laravel-2026-11-18.log` after setting the production `.env` values.
- [ ] Verify old Laravel log pruning after 14 days, PHP error-log destination/retention, and alerting without exposing secrets or unnecessary personal data.
- [ ] Confirm database and required storage backups run on a documented schedule.
- [ ] Perform and record a restore to a separate target; confirm the recovery process and recovery time.
- [ ] Document rollback steps, the known-good release, migration forward-fix strategy, and the responsible operator.

## 11. Privacy, terms, and retention

- [ ] Complete the controller legal identity, registered address, privacy contact, and any applicable DPO details in the public privacy notice. The current privacy notice is explicitly a draft with placeholders and is not approved for publication.
- [ ] Reconcile the company identity/address in config/easyenglish.php with the registered legal details and the published notice/terms.
- [ ] Obtain Romanian legal/privacy review of purposes, legal bases, processors, transfers, rights handling, cookies, account data, booking terms, withdrawal rights, cancellation, rescheduling, and refund disclosures.
- [ ] Approve category-specific retention periods for enquiries, account data, booking records, session notes, payment/refund records, notification records, logs, backups, and audit evidence.
- [ ] Keep DATA_RETENTION_ENABLED=false until the policy and legal basis are approved; test dry-run output before any authorized write run.
- [ ] Test data-subject request handling and the distinction between personal-data deletion/anonymization and statutory accounting retention.
- [ ] Record the approved document versions, reviewer, role, date, and unresolved legal/accounting blockers.

## 12. Analytics and consent

- [ ] Verify the actual production GTM container ID and that the deployed site loads the intended container.
- [ ] Verify the GA4 production property/web stream and that no development/test stream receives production traffic.
- [ ] Verify consent defaults and consent updates before Google tags fire; reject must prevent non-essential analytics/advertising tags from loading.
- [ ] Keep analytics and advertising consent separate and verify the consent UI persists the user's choice.
- [ ] Test lead/booking and purchase events using the current product flow.
- [ ] Verify purchase value and currency come from the authoritative server-side purchase data, use a stable transaction ID, and are not duplicated by redirects/webhook retries.
- [ ] Verify campaign/voucher attribution does not leak personal data into analytics.
- [ ] Document the actual GTM/GA4 configuration and remove stale statements that the tools are not configured.

## 13. Final release validation

- [ ] Run composer ci:check on the release commit.
- [ ] Run npm ci, npm test, and npm run build on the release commit.
- [ ] Confirm PHP, frontend, and OpenWA GitHub Actions jobs are green.
- [ ] Complete the production smoke test on desktop and mobile using the real Romanian customer journey.
- [ ] Complete a low-value live payment only after legal/accounting approval; verify Stripe, entitlement activation, invoice/receipt, notifications, and dashboard state end to end.
- [ ] Verify a standalone free introduction end to end without Stripe.
- [ ] Test a paid bundle booking using an existing entitlement, cancellation, rescheduling, teacher cancellation, no-show, and refund handling.
- [ ] Verify no critical production exceptions, failed jobs, or unhandled provider events remain.
- [ ] Record release SHA, deployment time, operator, smoke-test evidence, backup reference, rollback reference, and explicit GO/NO-GO approval.
- [ ] Every unchecked item is either completed or explicitly accepted as a documented risk. Do not treat unknown production state as passed.
