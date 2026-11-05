# Decision record: OpenWA scheduled WhatsApp calls

- Status: **Technical discovery complete; final real-device acceptance deferred to #220**
- Date: 2026-11-05
- Tracking issue: [#209](https://github.com/deputy-proxy/easyenglish-ro/issues/209)
- Final assembled-system acceptance: [#220](https://github.com/deputy-proxy/easyenglish-ro/issues/220)

## Context

EasyEnglish uses the official Meta WhatsApp Business Platform Cloud API for transactional messaging. It does not expose a WhatsApp voice/video call creation API. OpenWA is being evaluated separately as WhatsApp Web automation for scheduled video-call events.

The product requirement is a 15-minute introductory session. The customer and assigned teacher must receive the same join URL and be able to join the same WhatsApp call. EasyEnglish does not host call media. This technical discovery is not approval for production use.

## Deployment boundary

EasyEnglish's public website and Laravel application remain on Hostinger Cloud Startup. Hostinger's managed Web/Cloud Node.js offering does not support the persistent background process, Docker, and browser workload required by OpenWA. The disposable PoC is therefore isolated in the dedicated Railway project `easyenglish-openwa`, service `openwa-poc`.

The Railway service uses a Docker image with Chromium and zstd, one replica, and a 5 GB persistent volume mounted at `/data`. It is separate from Laravel and has no booking integration. The container runs as the unprivileged `node` user. Railway's restricted container runtime requires Chromium `--no-sandbox` flags for this PoC; this weakens browser isolation and is acceptable only for a disposable, authenticated experiment. It is not a production security approval.

Only three distinct non-production WhatsApp accounts may be used in the final test: paired creator, customer recipient, and teacher participant. No personal, primary business, or customer account may be paired.

## Technical provider contract

- Package: `@open-wa/wa-automate@5.10.0` (pinned in `poc/openwa-scheduled-calls/package.json` and lockfile).
- Runtime: Node.js `>=22.21.1`.
- Client creation: package export `create(options)`; session encryption uses `sessionDataEncryptionKeyFile`.
- Scheduled event creation: `client.sendEvent(chatId, event)`; v5.10.0 declarations return a serialized message ID.
- Event retrieval: `client.getEvent(eventId)`; the returned event may contain `joinLink` and `isCanceled`.
- Cancellation: `client.cancelEvent(eventId)`; declarations return a boolean.
- Event fields used by the PoC: `name`, `startTime`, `endTime`, `callType: 'video'`, `isScheduledCall`, and `extraGuestsAllowed`. Start/end are Unix seconds.
- Teacher link sharing: `client.sendText(chatId, joinLink)`.

The PoC polls `getEvent` for a bounded interval after `sendEvent`. If submission or retrieval has an uncertain outcome, it persists that state and refuses automatic resubmission. It verifies that creator, customer, and teacher account IDs are distinct before submitting an event. A small fake OpenWA client is used by Node's built-in test suite to exercise the contract without live WhatsApp credentials. The production-grade provider adapter and Laravel-facing abstraction belong to implementation issue #210.

Cancellation is exposed in the PoC. Rescheduling/update behavior is not established and must be treated as unsupported until explicitly validated. Event creation/link retrieval are technically supported by the package declarations and PoC code, but native invitation delivery, participant joining, scheduled-call behavior, and reconnect behavior remain unverified against real devices.

## Runtime and security findings

- Required runtime components include Node.js, Chromium, zstd, HTTPS configuration, a durable writable session volume, and a separate private encryption-key directory.
- The session is configured for encrypted persistence using a dedicated 32-byte key file. Session/key directories are outside the application root and have restrictive permissions.
- The Railway service is configured with one replica and a persistent `/data` volume. Its deployment reports `SUCCESS`; `/health/live` is a liveness signal only.
- The Railway start command is configured as `npm run preflight && node src/server.js`, but available runtime logs do not contain explicit preflight output. Therefore deployed-container preflight execution is **not yet verified**.
- `OPENWA_ALLOW_LIVE_TEST=false`. No QR pairing, WhatsApp account pairing, event creation, or live participant test has been performed.
- Railway's Chromium no-sandbox requirement reduces browser isolation. Keep the PoC authenticated and isolated; reassess before any production design.

## Dependency security review

The pinned dependency tree has seven low-severity audit findings and zero high/critical findings when checked with `npm audit --audit-level=high`. The remaining low finding is prototype poisoning in `deepmerge`, pulled in through `puppeteer-extra`. Two high-severity transitive advisories were addressed with lockfile overrides for `basic-ftp@6.2.6` and `image-size@2.0.4`.

Do not run `npm audit fix --force`: it proposes downgrading OpenWA to v4.76.0 and would invalidate the v5 API contract. The residual low finding and unofficial WhatsApp Web automation/account-restriction risks remain documented risks, not proof of safety.

## Automated validation evidence

Latest local validation in the persistent development Sandbox (2026-11-05):

- `npm ci`: passed.
- `npm test`: **27 passed, 0 failed**.
- `npm audit --audit-level=high`: passed threshold; seven low findings remain, zero high/critical.
- Tests cover configuration/security validation, authentication/rate limiting, E.164 and future-time validation, three-account separation guards, 15-minute event creation, retrieval/link sharing, uncertain outcomes, cancellation, idempotency, and encrypted result storage.

The repository CI also has a dedicated `openwa-poc` job that runs `npm ci`, `npm test`, and `npm audit --audit-level=high` in this directory. Full repository validation and GitHub Actions are still required before merging the issue PR.

## Interim recommendation

**Proceed with downstream implementation using a provider adapter, fake provider/client, explicit feature flags, and fail-closed handling for unsupported provider operations.** Do not wait for real-device pairing to build the service, Laravel persistence, booking orchestration, notifications, or operational controls.

Do not enable real customer traffic or treat OpenWA as production-approved. Keep `OPENWA_ALLOW_LIVE_TEST=false` until the final test is deliberately scheduled and approved. Final real-device and assembled-system validation, including the GO/NO-GO decision and production approval, belongs to #220 after the complete staging journey exists.

## Final acceptance evidence deferred to #220

- Explicitly confirm deployed Railway-container preflight output.
- Pair the dedicated non-production creator account.
- Verify session restoration after process restart/reconnect.
- Create and retrieve a future scheduled video event and record native invitation behavior.
- Share the exact same join URL with a separate teacher account.
- Join the same call from supported Android and iOS clients.
- Test creator disconnect, cancellation, timeout/duplicate behavior, and recovery.
- Record sanitized evidence, account/device roles, limitations, and final GO/NO-GO recommendation.

No production integration or rollout is authorized by this discovery record.
