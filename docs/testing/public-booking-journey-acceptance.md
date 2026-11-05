# Public booking journey acceptance plan

**Owner:** Product and Operations  
**Scope:** Final assembled-system acceptance for issues #212–#219  
**Last reviewed:** 5 November 2026  
**Status:** Automated coverage passes locally; real-device staging acceptance is blocked by missing staging integration credentials and account-pairing prerequisites.

This is the traceability record for [issue #220](https://github.com/deputy-proxy/easyenglish-ro/issues/220). It separates repeatable automated checks from final staging-only device checks. A green fake-provider suite is not evidence that a real WhatsApp account can join a scheduled call.

## 1. Traceability to implementation issues

| Issue | Workstream | Automated evidence |
|---|---|---|
| [#212](https://github.com/deputy-proxy/easyenglish-ro/issues/212) | OpenWA real-device proof-of-concept and call-event contract | `poc/openwa-scheduled-calls/test/*.test.js`; live-device checks in section 4 |
| [#213](https://github.com/deputy-proxy/easyenglish-ro/issues/213) | Isolated Node service, authenticated API, pairing guard, encrypted store, readiness and retries | OpenWA PoC suite; `npm audit --audit-level=high` |
| [#214](https://github.com/deputy-proxy/easyenglish-ro/issues/214) | Laravel provider adapter, persistence, queue jobs and authorized retrieval | `tests/Feature/BookingCallEventTest.php` |
| [#215](https://github.com/deputy-proxy/easyenglish-ro/issues/215) | Booking lifecycle, teacher assignment, call-event orchestration and notifications | `BookingDomainTest.php`, `TeacherAvailabilityTest.php`, `NotificationDeliveryTest.php`, `BookingCallEventTest.php` |
| [#216](https://github.com/deputy-proxy/easyenglish-ro/issues/216) | Deployment controls, monitoring, staff alerts, recovery and rollout | OpenWA readiness/rollout tests, operational runbooks and ADR |
| [#217](https://github.com/deputy-proxy/easyenglish-ro/issues/217) | Inline registration/login, slot/locale preservation and accessible AJAX states | `PublicBookingJourneyTest.php`, `tests/js/booking-auth.test.js` |
| [#218](https://github.com/deputy-proxy/easyenglish-ro/issues/218) | Inline email/WhatsApp verification and free-session eligibility | `ContactVerificationTest.php`, `BookingDomainTest.php` |
| [#219](https://github.com/deputy-proxy/easyenglish-ro/issues/219) | Previously-used free-session bundle selection and Stripe flow | `ContactVerificationTest.php`, `StripeCheckoutTest.php` |
| #220 | Cross-workstream integration, dashboard outcome and device acceptance | `PublicBookingJourneyTest.php`, `DashboardTest.php`, `BookingCallEventTest.php`, this plan |

## 2. Automated acceptance matrix

| Area | Covered scenarios | Evidence |
|---|---|---|
| Landing page / availability | RO/EN, seven-day slots, timezone, empty/error states, server-bookable slots | `PublicBookingJourneyTest.php`, `TeacherAvailabilityTest.php` |
| Registration / login | Valid and invalid credentials, duplicate registration, session regeneration, selected-slot retention, Fortify 2FA, no dashboard redirect after login | `PublicBookingJourneyTest.php`, `booking-auth.test.js` |
| AJAX | Loading state, duplicate-submit suppression, 422, invalid credentials, 419, 429, 5xx, network interruption, structured verification errors | `booking-auth.test.js` |
| Contact verification | Both channels, email-only, matching verified-channel skip, changed phone, invalid/expired/consumed/locked codes, cooldown, throttling and delivery failure | `ContactVerificationTest.php`, `booking-auth.test.js` |
| Eligibility | Normalized email OR phone, prior claims, teacher-cancelled exception, read-only preflight and final transactional recheck | `ContactVerificationTest.php`, `BookingDomainTest.php` |
| Availability / teacher | Past/stale slots, overlapping holds, busy/disconnected calendars, no eligible teacher, allocation and calendar sync idempotency | `BookingDomainTest.php`, `TeacherAvailabilityTest.php` |
| Booking lifecycle | Hold expiry, confirmation idempotency, claim/credit rollback, cancellation, reschedule, no-show and duplicate requests | `BookingDomainTest.php`, `TeacherAvailabilityTest.php` |
| Bundle branch | Live active bundle data, no booking/hold/teacher/claim/call event, checkout disabled/cancelled/pending/failed, webhook proof and replay protection | `ContactVerificationTest.php`, `StripeCheckoutTest.php` |
| OpenWA service | Authenticated API, pairing default-off, encrypted persistence, idempotency, uncertain timeout, malformed response, readiness/disconnection, retrieve/cancel | 42 Node tests in `poc/openwa-scheduled-calls` |
| Laravel call events | Bearer auth, config validation, persistence, retries/reconciliation, stale revisions, cancellation and authorization | `BookingCallEventTest.php` |
| Notifications | Customer/teacher separation, same link, consent/shared-number rules, independent delivery retry and stale-revision suppression | `BookingCallEventTest.php`, `NotificationDeliveryTest.php` |
| Dashboard outcome | Confirmed booking redirect, pending/failed/ready states, current-revision-only join, private authorized redirect | `DashboardTest.php`, `PublicBookingJourneyTest.php` (guest registration → email/WhatsApp verification → booking → teacher assignment → call-event creation → customer/teacher notification outbox → dashboard), `BookingCallEventTest.php` |

### Known automation limits

The Laravel suite exercises duplicate slot attempts, the database mutex, idempotent retries, and an interleaved eligibility preflight followed by the final transactional recheck. The OpenWA service tests concurrent duplicate requests against its serialization lock. The local Laravel test database does not reproduce production MySQL row-lock scheduling across independent worker processes. A parallel same-slot and same-contact load test against staging is still required; sequential/interleaved tests are not proof of a production concurrency test.

JavaScript unit tests cover request states, CSRF/session headers, network/server errors, duplicate submissions and 2FA handoff, but do not run a browser engine. Browser refresh/back, focus order, keyboard traversal and responsive desktop/mobile layouts remain manual.

## 3. Automated execution

Run from the repository root:

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer ci:check
npm test
npm run build
cd poc/openwa-scheduled-calls
npm ci
npm test
npm audit --audit-level=high
```

`composer ci:check` runs Pint, PHPStan and PHP tests. The root `npm test` runs frontend unit tests; `npm run build` verifies the production bundle. The OpenWA PoC uses its own lockfile.

The OpenWA audit currently reports seven **low** severity findings in transitive `deepmerge` through `puppeteer-extra` / `@open-wa/wa-automate`; `npm audit --audit-level=high` passes. `npm audit fix --force` proposes a breaking `@open-wa/wa-automate` version change. Do not apply that automatically; validate provider compatibility before changing the pinned version.

Latest local validation: Pint passed (224 files); PHPStan passed; 318 PHP tests / 1,867 assertions passed; 19 frontend JavaScript tests passed; 42 OpenWA Node tests passed; production frontend build passed; `npm audit --audit-level=high` passed with seven low-severity transitive findings. The build retains the existing unresolved `/images/easyenglish-symbol-pattern.svg` runtime-asset warning. Record the final commit SHA and GitHub CI run URL in the PR. Green CI is necessary but does not close the manual gate.

## 4. Manual staging acceptance matrix

**Do not run this against the Hostinger production application or a personal/primary WhatsApp account.** Use a dedicated non-production creator account and independent customer and teacher accounts. Do not enable customer rollout while testing.

| ID | Test | Expected result | Evidence | Status |
|---|---|---|---|---|
| M-01 | Pair dedicated creator account in temporary authenticated pairing window | Readiness healthy; pairing endpoints disabled immediately after pairing | Sanitized service status and timestamp | Blocked: pairing prerequisites missing |
| M-02 | Restart service and reconnect creator session | Encrypted session restores from same volume; no unnecessary re-pair | Deployment ID and readiness transitions | Pending |
| M-03 | Create/retrieve event from confirmed staging booking | One provider event ID, valid join URL, same idempotency key on retry | Booking revision and sanitized state transition | Pending |
| M-04 | Deliver link to customer and assigned teacher | Both receive the same current-revision link; recipient failures remain independent | Redacted delivery records and acknowledgements | Pending |
| M-05 | Join from Android and iOS | Both participants join the same call; audio/video and roles work | Device model, OS/app version, role and result | Pending |
| M-06 | Restart/reconnect during scheduled event window | Session restores; event remains addressable; no duplicate provider event | Before/after event reference and readiness | Pending |
| M-07 | Cancel and reschedule booking | Old event is cancelled and old link inaccessible; only new revision is visible | Old/new revision and sanitized transitions | Pending |
| M-08 | Simulate provider outage and notification failure | Booking stays confirmed; dashboard shows pending/failed; recipient retries are independent | Booking/event/delivery status and staff alert | Pending |
| M-09 | Restore encrypted volume backup in isolated non-production | Matching key restores session/event data; `/data` mounted; health behavior correct | Backup and restore deployment references | Blocked: backup schedule/drill unverified |
| M-10 | Emergency disable and recovery | Rollout off stops new events; existing bookings remain confirmed; re-enable requires approval | Config change and event state | Pending |
| M-11 | Full browser journey on desktop and mobile | Slot, locale and form state survive registration/login/verification; keyboard/focus and refresh/back work | Browser, viewport, locale and case ID | Pending |
| M-12 | Dashboard and access control | Customer sees call status; only customer/assigned teacher can open current link; unrelated user and stale revision denied | Role, revision, HTTP result and sanitized screenshot | Automated route tests pass; manual staging pending |

### Evidence handling

- Never capture QR payloads, pairing secrets, service tokens, raw phone numbers, personal emails, session storage or full join URLs in GitHub, CI logs, screenshots or chat.
- Redact names, contact details, identifying event IDs and credentials. Prefer sanitized test notes over raw logs.
- Record device model, OS and WhatsApp app versions, role, booking revision, event state and result. Store evidence in the approved restricted operations location and link only to a sanitized reference.
- Use synthetic customer/teacher identities and non-production bookings. Do not message real customers.

## 5. Current blockers and safety boundary

The connected Railway project reports the `openwa-poc` service online with one replica and a persistent 5 GB volume; its latest deployment reports success. This establishes container liveness only. The Railway variable inventory does **not** contain `OPENWA_SERVICE_TOKEN`; `OPENWA_PAIRING_ENABLED` is absent and therefore remains off by default. The API is designed to fail closed without its service token. An authenticated staging Laravel-to-OpenWA connection is not provisioned here.

The Railway environment is named `production`, although the OpenWA service is isolated from the Hostinger Laravel application and intended for a dedicated non-production WhatsApp account. Do not treat it as a staging environment. Before M-01, provision an explicitly named staging Laravel environment, create a high-entropy matching service token in both secret stores, verify public HTTPS connectivity, and keep Laravel `OPENWA_ENABLED` off until the internal-test allowlist and pairing review are complete. Keep pairing disabled outside the approved pairing window. Secret values are not exposed by the connected environment view; no value has been inferred.

The Railway volume backup schedule and authenticated-session restore drill are unverified. The unofficial WhatsApp Web automation terms/account-risk review and final privacy/retention approval are also not recorded. These remain production rollout blockers even after mobile call tests pass.

**Safety rule:** do not set `OPENWA_ENABLED=true`, enable pairing, set `OPENWA_ALLOW_LIVE_TEST=true`, or connect the Hostinger production app as part of this automated work. Product and Operations must explicitly approve the staging pairing window and dedicated creator account before real-device tests. Production rollout requires a separate GO decision after the full matrix and recovery checks pass.

## 6. Sign-off

| Gate | Owner | Evidence | Status |
|---|---|---|---|
| Automated CI | Engineering | Local suite: 318 PHP tests / 1,867 assertions, 19 frontend tests, 42 OpenWA tests; Pint, PHPStan and build passed | Local pass; final PR CI pending |
| Staging integration | Engineering / Operations | Staging URL, matching secret presence, authenticated readiness/API check | Blocked |
| Real-device Android/iOS | Product / Operations | M-01–M-08 and M-12 | Blocked |
| Backup restore and emergency recovery | Operations | M-09 and M-10 | Blocked |
| Provider terms and retention review | Product / Legal | Recorded review and approved schedule | Pending |
| Product/Operations GO | Product owner + Operations owner | Signed acceptance with known limitations | Not approved |

Do not close issue #220 or enable general customer rollout until all required gates have evidence and an explicit GO decision.
