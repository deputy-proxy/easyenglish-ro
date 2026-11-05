# Decision record: OpenWA scheduled WhatsApp calls

- Status: **Pending real-device proof of concept**
- Date: 2026-11-05
- Tracking issue: [#209](https://github.com/deputy-proxy/easyenglish-ro/issues/209)

## Context

EasyEnglish uses the official Meta WhatsApp Business Platform Cloud API for transactional messages. It does not provide a WhatsApp voice/video call creation API. OpenWA is being evaluated for creating native scheduled WhatsApp video-call events as a separate integration.

The product requirement is a 15-minute introductory session. A customer and assigned teacher must receive the same join URL and join the same WhatsApp call. EasyEnglish does not host call media.

## Approved deployment boundary

The EasyEnglish production website runs on Hostinger Cloud Startup. Hostinger confirms that managed Web/Cloud Node.js hosting does not support always-running background workers/bots, Docker, or additional services. Since OpenWA requires a persistent WhatsApp Web session and Chromium, Cloud Startup is not an approved runtime. Keep Laravel on Cloud Startup and deploy the disposable PoC as a separate Railway service in a dedicated `easyenglish-openwa` project. The Railway service uses a Docker image with Chromium and zstd, a single replica, and a persistent volume for encrypted session state. It must not run inside Laravel, share Laravel's document root, or connect to booking traffic.

Only three distinct non-production WhatsApp accounts may be used: paired creator, customer recipient, and teacher participant.

## Hosting evidence

- [Hostinger Node.js limits for Web and Cloud plans](https://www.hostinger.com/support/hostinger-node-js-limits-for-web-and-cloud-plans/) state that always-running background workers/bots, custom ports, Docker, and additional services are not supported on managed Web/Cloud plans.
- [Hostinger Node.js hosting options](https://www.hostinger.com/support/node-js-hosting-options-at-hostinger/) distinguishes managed Web/Cloud apps from VPS deployments. Railway is selected here to host the isolated persistent Node.js/Chromium service.

## Technical evidence

The implementation pins `@open-wa/wa-automate@5.10.0`. The installed package declarations confirm:

- `sendEvent(to, event)` returns a serialized message ID.
- `getEvent(eventId)` retrieves the corresponding event and can return `joinLink`.
- `cancelEvent(eventId)` returns a boolean.
- Event inputs support video scheduled calls with start/end Unix timestamps.
- OpenWA supports a `sessionDataEncryptionKeyFile` option for encrypted session persistence.

The PoC polls event retrieval after creation and does not blindly repeat an uncertain event submission. It verifies that the paired creator, customer, and teacher IDs are distinct before event creation.

## Dependency security review

Initial `npm audit` found 14 high and 4 low findings. The lockfile now overrides `basic-ftp` to 6.2.6 and `image-size` to 2.0.4, addressing the two high-severity transitive advisories in the OpenWA dependency tree. The resulting audit reports zero high/critical findings and seven low findings. The remaining low finding concerns prototype poisoning in `deepmerge`, pulled in through `puppeteer-extra`. This is documented in the integration runbook and remains a residual risk for review.

Do not use `npm audit fix --force`; it proposes downgrading OpenWA to v4.76.0, which would invalidate the v5 API contract validated here.

## Automated validation

- OpenWA 5.10.0 import and `create` export verified in the development sandbox.
- Twenty-six Node tests pass.
- API type declarations inspected for event creation, retrieval, cancellation, and session encryption configuration.
- The actual Railway container runtime and browser sandbox have not yet been tested.

## Outstanding evidence

- Railway container preflight for Node, Chromium sandbox, zstd, and persistent volume storage.
- Authenticated QR pairing using the dedicated creator account.
- Session restore after process restart.
- Native customer event invitation and event metadata.
- Exact same URL delivered to teacher.
- Real Android and iOS join tests.
- Duplicate submission, cancellation, timeout, creator disconnect, and account-risk observations.

## Decision

**No GO/NO-GO decision yet.** Do not integrate OpenWA with Laravel booking or production messaging until the real-device test matrix is complete. If a core capability fails or account risk is unacceptable, stop and evaluate an official or alternative calling workflow.
