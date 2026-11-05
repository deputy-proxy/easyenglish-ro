# OpenWA scheduled-call proof of concept

## Purpose and boundary

This is a disposable, isolated proof of concept for testing native scheduled WhatsApp video-call events. It is not part of Laravel, does not handle bookings, and must not receive production customer data or production WhatsApp credentials. The existing Meta WhatsApp Business Platform integration remains the approved transactional messaging channel.

The PoC runs as a separate Node.js application on the existing EasyEnglish Hostinger production server, as approved in issue #209. It uses a temporary HTTPS subdomain, its own process lifecycle, separate private persistent session-data and encryption-key directories, and an authenticated pairing interface. It must not share Laravel's document root or connect to Laravel booking traffic.

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

## Hostinger deployment

Deploy this directory as a separate Node.js application in Hostinger. Do not deploy it inside Laravel's application root or Laravel's public directory.

1. Create a temporary subdomain, for example `openwa-poc.easyenglish.ro`, and enable HTTPS.
2. Configure a separate Node.js application with this directory as its application root and `npm start` as its start command.
3. Select Node.js 22.21.1 or newer. The PoC package pins OpenWA 5.10.0.
4. Set the application port using Hostinger's assigned `PORT` value. Bind to the interface required by Hostinger's Node application proxy.
5. Create two persistent directories outside every public web root, for example `/home/USER/private/easyenglish-openwa/session` and `/home/USER/private/easyenglish-openwa/keys`. They must be separate from each other and from the Node application root. Restrict both to the application account with directory mode 0700 and file mode 0600.
6. Configure the environment variables from `.env.example`. Generate a fresh encryption key with `node -e "console.log(require('node:crypto').randomBytes(32).toString('base64'))"`. Generate a separate high-entropy pairing password. Put both in Hostinger's server-side environment settings, never in source control.
7. Set `OPENWA_PUBLIC_BASE_URL` to the HTTPS subdomain. Set `OPENWA_SESSION_DIR` and `OPENWA_SESSION_KEY_DIR` to the separate private directories. Set `OPENWA_CHROME_EXECUTABLE` only if Hostinger's Chrome/Chromium binary is not discoverable on PATH.
8. Install locked dependencies with `npm ci`.
9. Run the preflight command below from the actual Hostinger runtime. Do not proceed if it fails.
10. Review the current `npm audit` result. Do not enable pairing while high/critical findings remain.
11. Keep `OPENWA_ALLOW_LIVE_TEST=false` for the first deployment. In this mode the Node app starts its protected status page and health endpoint but does not launch OpenWA or display a QR. Run the runtime, browser sandbox, zstd, storage permissions, TLS, authentication, and audit checks. Only after they pass, explicitly set the flag to `true` and restart the PoC process.
12. Open the HTTPS subdomain in a browser. Basic authentication prompts for the configured pairing username/password. The QR code is only available behind authentication and is returned with no-cache headers.
13. On the dedicated OpenWA test phone, open WhatsApp → Linked devices → Link a device and scan the QR displayed in the protected page.
14. Once authenticated, enter the separate customer and teacher test numbers and a future Bucharest-local time. The service creates the event for the customer and sends the same retrieved join URL to the teacher.
15. After testing, set `OPENWA_ALLOW_LIVE_TEST=false`, stop the Node.js process, remove the temporary subdomain, and revoke/unlink the dedicated OpenWA device from WhatsApp. Retain or securely delete the encrypted session data according to the test record.

### Hostinger preflight

```sh
npm run preflight
```

The preflight checks Node.js version, presence of zstd, Chrome/Chromium availability and headless launch, HTTPS pairing configuration, the 32-byte session encryption key, and writable persistent storage. It does not pair an account or create an event.

A successful sandbox preflight does not prove Hostinger compatibility. The command must pass on the actual Hostinger application runtime before pairing. Do not work around browser sandbox failures by blindly adding `--no-sandbox`; investigate the host's supported browser configuration first.

## Security controls

- The pairing interface and all operations except `/health/live` require HTTP Basic authentication over HTTPS.
- The pairing password must be at least 32 characters. Authentication failures are rate-limited per source IP.
- QR images and responses use no-store/no-cache headers. QR values are never logged.
- Session data is encrypted by OpenWA using a dedicated 32-byte key file. The key is sourced from Hostinger environment configuration and written with 0600 permissions into a 0700 private key directory that is separate from the encrypted session-data directory. The event-result record is also encrypted with AES-256-GCM. The browser profile is ephemeral.
- Session files, event records, encryption keys, and logs remain outside the public web root and outside source control.
- The service binds to its own Node process and does not expose OpenWA's raw API, browser debugging port, or Laravel routes.
- Phone numbers are not written to routine logs or persisted in the PoC result record; only truncated SHA-256 references are recorded.
- Event creation requires an idempotency key. Repeating the same key returns the original result; a different key is rejected while an earlier event remains active. The event ID and join URL are persisted privately to prevent duplicate submissions after restart.
- An uncertain provider result is not retried automatically.
- The PoC contains no production booking integration and no production customer messaging.

## Test matrix

| Test | Expected result | Status |
|---|---|---|
| Hostinger runtime preflight | Node, zstd, Chrome sandbox, HTTPS, encrypted key, and persistent storage pass | Pending Hostinger deployment |
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
