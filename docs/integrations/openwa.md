# OpenWA scheduled-call proof of concept

## Purpose and boundary

This is a disposable, isolated proof of concept for testing native scheduled WhatsApp video-call events. It is not part of Laravel, does not handle bookings, and must not receive production customer data or production WhatsApp credentials. The existing Meta WhatsApp Business Platform integration remains the approved transactional messaging channel.

The PoC runs as a separate Railway service, independent from the EasyEnglish website. The confirmed EasyEnglish plan is Hostinger Cloud Startup, which supports ordinary Node.js web applications but explicitly does not support always-running background workers/bots, Docker, or additional services. OpenWA requires a persistent WhatsApp Web session and a Chromium process, so Cloud Startup is not a supported runtime. Keep Laravel and the public website on Cloud Startup; run OpenWA in a dedicated Railway project with a persistent volume. The PoC uses its own service lifecycle, separate private session-data and encryption-key directories on the volume, and an authenticated HTTPS pairing interface. It must not share Laravel's document root or connect to Laravel booking traffic.

The PoC requires three distinct non-production WhatsApp accounts:

1. OpenWA creator/sender, paired to the service.
2. Customer test recipient, receiving the native event invitation.
3. Teacher test participant, receiving the same join URL in a separate message.

Never use a personal or primary business WhatsApp account.

## Version and API contract

- Package: `@open-wa/wa-automate`
- Pinned version: `5.10.0`
- Node.js minimum: `22.21.1`
- Event creation: `client.sendEvent(to, event)`
- Event retrieval: `client.getEvent(eventId)`
- Cancellation: `client.cancelEvent(eventId)`
- Teacher link delivery: `client.sendText(to, joinLink)`

The installed v5.10.0 declarations confirm that `sendEvent` returns a serialized WhatsApp message ID, and `getEvent` accepts that ID as `eventId`. The event input accepts `name`, `startTime`, `endTime`, `callType: "video"`, `isScheduledCall`, and `extraGuestsAllowed`. The event response can include `joinLink` and `isCanceled`. The start and end times are Unix seconds.

The implementation does not assume event creation implies immediate link availability. It polls `getEvent` for up to six seconds. If the event was submitted but no link appears, it records an uncertain result and explicitly refuses to submit a second event automatically.

## Local development and tests

From this directory:

```sh
npm ci
npm test
npm audit
```

The tests use Node's built-in test runner and a fake OpenWA client. They cover Basic authentication and rate limiting, configuration validation, HTTPS-only pairing, separate private storage paths, AES-256-GCM result encryption, E.164 validation, 15-minute duration, distinct creator/customer/teacher accounts, event retrieval, teacher link delivery, uncertain event creation, cancellation, and duplicate-request decisions.

The package lock includes two reviewed npm overrides:

- `basic-ftp@6.2.6` addresses the upstream high-severity directory-listing denial-of-service advisory in the version range used by the OpenWA dependency tree.
- `image-size@2.0.4` addresses the upstream high-severity ICNS parser denial-of-service advisory in the version range used by the OpenWA dependency tree.

After those overrides, `npm audit` reports zero high/critical findings and seven low findings. The remaining low finding is prototype poisoning in `deepmerge` (pulled in through `puppeteer-extra`). No user-supplied objects are passed to that merge path in this PoC, but the residual finding must remain documented and reviewed before enabling the service. Do not run `npm audit fix --force`: it proposes a major downgrade of OpenWA to v4.76.0 and would invalidate the v5 API validation.

OpenWA is an unofficial WhatsApp Web automation package. Its use can violate or conflict with WhatsApp's terms and can result in account restrictions. A dedicated disposable account is mandatory. The dependency audit is not a guarantee that the package is safe.

## Railway deployment

Deploy this PoC as a dedicated Railway service in a separate project named `easyenglish-openwa`. Do not add it to the Laravel/website deployment. The service uses the Dockerfile in this directory, which installs Chromium and zstd and runs the Node process as an unprivileged user. The container entrypoint prepares the persistent volume directories with private permissions.

1. Create a Railway project named `easyenglish-openwa` in the existing Proxy Applications workspace, with a `production` environment.
2. Create an empty service named `openwa-poc` and set its root directory to `/poc/openwa-scheduled-calls` in the repository. Use the included `Dockerfile`.
3. Attach a Railway volume at `/data` (1 GB is sufficient for this PoC's session and encrypted result record). The volume must be in the same region as the service. Do not run multiple replicas: the WhatsApp session is single-writer state.
4. Configure these variables on the service: `NODE_ENV=production`, `HOST=0.0.0.0`, `OPENWA_SESSION_ID=easyenglish-openwa-poc`, `OPENWA_SESSION_DIR=/data/session`, `OPENWA_SESSION_KEY_DIR=/data/keys`, `OPENWA_CHROME_EXECUTABLE=/usr/bin/chromium`, `OPENWA_SESSION_ENCRYPTION_KEY` (fresh base64-encoded 32-byte random key), `OPENWA_PAIRING_USERNAME`, `OPENWA_PAIRING_PASSWORD` (at least 32 random characters), `OPENWA_PUBLIC_BASE_URL` (the generated Railway HTTPS domain), and `OPENWA_ALLOW_LIVE_TEST=false`.
5. Generate a Railway HTTPS domain for the service and set that exact URL as `OPENWA_PUBLIC_BASE_URL`. Route the domain to the service's `PORT`; Railway injects this variable at runtime.
6. Configure `/health/live` as the Railway healthcheck path and set restart policy to `ALWAYS`. Keep replicas at one. Do not enable serverless sleep for this persistent-session service.
7. Run `npm run preflight` in the deployed container after variables and volume are configured. Confirm Node version, Chromium headless launch, zstd, HTTPS configuration, encryption key, and volume write permissions.
8. Keep `OPENWA_ALLOW_LIVE_TEST=false` during initial deployment. In this mode the service starts its protected status page and health endpoint but does not launch OpenWA or display a QR. Review the current `npm audit` result; do not enable pairing while high/critical findings remain.
9. Only after all checks pass, explicitly set `OPENWA_ALLOW_LIVE_TEST=true` and restart the service. Open the HTTPS domain in a browser. Basic authentication prompts for the configured pairing username/password. The QR code is only available behind authentication and is returned with no-cache headers.
10. On the dedicated OpenWA test phone, open WhatsApp → Linked devices → Link a device and scan the QR displayed in the protected page.
11. Once authenticated, enter the separate customer and teacher test numbers and a future Bucharest-local time. The service creates the event for the customer and sends the same retrieved join URL to the teacher.
12. After testing, set `OPENWA_ALLOW_LIVE_TEST=false`, restart the service, revoke/unlink the dedicated OpenWA device from WhatsApp, and securely delete the Railway volume if the test data is no longer needed.

### Railway runtime notes

- The service is intentionally single-replica because one WhatsApp Web session and its persisted browser state must have one active writer.
- `/data/session` and `/data/keys` are separate private directories on the same Railway volume. The encryption key remains in Railway service variables and is written to the volume with mode 0600; the session and key directories are mode 0700.
- The Docker image installs Debian Chromium and `zstd`; `OPENWA_CHROME_EXECUTABLE` points to `/usr/bin/chromium`.
- The service must remain continuously running. Do not configure a Railway cron schedule or serverless sleep.
- A successful local or sandbox preflight does not prove Railway compatibility. The preflight must pass in the deployed Railway container, under the unprivileged `node` user, before pairing.
- Do not add `--no-sandbox` blindly. If Chromium cannot launch in Railway, investigate the sandbox/runtime failure before enabling live pairing.

## Security controls

- The pairing interface and all operations except `/health/live` require HTTP Basic authentication over HTTPS.
- The pairing password must be at least 32 characters. Authentication failures are rate-limited per source IP.
- QR images and responses use no-store/no-cache headers. QR values are never logged.
- Session data is encrypted by OpenWA using a dedicated 32-byte key file. The key is sourced from the protected VPS environment configuration and written with 0600 permissions into a 0700 private key directory that is separate from the encrypted session-data directory. The event-result record is also encrypted with AES-256-GCM. The browser profile is ephemeral.
- Session files, event records, encryption keys, and logs remain outside the public web root and outside source control.
- The service binds to its own Node process and does not expose OpenWA's raw API, browser debugging port, or Laravel routes.
- Phone numbers are not written to routine logs or persisted in the PoC result record; only truncated SHA-256 references are recorded.
- Event creation requires an idempotency key. Repeating the same key returns the original result; a different key is rejected while an earlier event remains active. The event ID and join URL are persisted privately to prevent duplicate submissions after restart.
- An uncertain provider result is not retried automatically.
- The PoC contains no production booking integration and no production customer messaging.

## Test matrix

| Test | Expected result | Status |
|---|---|---|
| Railway runtime preflight | Node, zstd, Chrome sandbox, HTTPS, encrypted key, and persistent storage pass | Pending Railway deployment |
| Pair dedicated creator account | QR is displayed only behind authenticated HTTPS page | Pending |
| Restore session after restart | Authenticated state returns without re-pairing | Pending |
| Create future 15-minute video event | Native event invitation appears in customer chat | Pending |
| Retrieve event metadata | Event ID and join URL are returned | Pending |
| Share URL with teacher | Teacher receives the exact same URL | Pending |
| Android customer joins | Customer joins the scheduled event | Pending |
| Android/iOS teacher joins | Teacher joins the same call | Pending |
| Creator/customer/teacher separation | All three accounts are distinct | Automated guard implemented; real account check pending |
| Duplicate idempotency key | Original event is returned; no second event is created | Automated guard implemented; live verification pending |
| Different key while event active | Request rejected with conflict | Automated guard implemented; live verification pending |
| Invalid participant numbers | Request rejected before provider call | Automated test passed |
| Event retrieval delay/failure | Uncertain result retained; no automatic resubmission | Automated test passed |
| Event cancellation | Existing event is cancelled | Pending live verification |
| Creator disconnected at event time | Record whether event remains joinable | Pending |
| Account restriction/operational risk | Document observations; do not use primary account | Pending |

## Decision status

**Pending. No GO decision has been made.** Automated tests and package API inspection are not substitutes for the required Hostinger deployment and real-device evidence. Do not start production service implementation or connect OpenWA to booking traffic until issue #209's acceptance criteria have been completed and the product owner has approved the decision.
