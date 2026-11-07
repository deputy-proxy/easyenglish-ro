# OpenWA scheduled-call service

## Purpose and architecture

OpenWA is isolated in a standalone Node.js service. Laravel remains the system of record for users, bookings, teacher assignment, payment state, and notification delivery. Laravel calls this service over an HTTPS service-to-service API protected by a high-entropy Bearer token; it must never connect to the OpenWA browser or raw client directly.

OpenWA is the selected primary outbound provider for Easy English transactional WhatsApp messages and scheduled WhatsApp video-call events. Meta WhatsApp Cloud API remains configured as an operator-selected fallback, not the normal outbound route. OpenWA uses unofficial WhatsApp Web automation, is not an official WhatsApp Business Platform API, and may conflict with WhatsApp terms or result in account restrictions. The business has explicitly accepted this operational risk for the dedicated EasyEnglish account. Do not use a personal WhatsApp account.

The service owns one OpenWA session and one durable encrypted event store. Outbound networking requires DNS and HTTPS/WSS egress to WhatsApp Web and required provider endpoints; inbound traffic is limited to the Railway HTTPS listener on port 8080. Do not expose raw Chromium debugging ports. Run exactly one replica. Do not embed OpenWA, Chromium, or browser automation in the Laravel runtime. The service is deployed separately from the EasyEnglish website because the confirmed Hostinger Cloud Startup plan does not support the persistent browser process and separate services required by OpenWA. Laravel runs on Hostinger while OpenWA runs in Railway. Railway private DNS is scoped to services in the same Railway project and environment, so Hostinger must use the Railway HTTPS domain with a mandatory high-entropy Bearer token. Do not configure a `.railway.internal` URL in Hostinger.

## Provider contract

- Package: `@open-wa/wa-automate@5.10.0` (pinned)
- Node.js: `22.21.1` or newer
- Create: `client.sendEvent(chatId, event)`
- Retrieve: `client.getEvent(providerEventId)`
- Cancel: `client.cancelEvent(providerEventId)`
- Event timestamps: Unix seconds
- Supported event type: scheduled video call

The installed v5.10.0 declarations and PoC verify that `sendEvent` returns a serialized WhatsApp message ID accepted by `getEvent`; a retrieved event can expose `joinLink` and `isCanceled`. Event creation and URL availability are separate operations. The adapter polls retrieval for up to six attempts. If creation or retrieval has an uncertain outcome, the service records that state and refuses automatic resubmission.

The provider receives a destination E.164 phone number, which is converted to the OpenWA chat ID. The same returned join URL is then available to Laravel. Participant notifications are sent by Laravel through the selected WhatsApp messaging gateway, currently OpenWA. The call-event API and messaging API remain separate capabilities with independent feature flags and idempotency stores.

## HTTP API v1

All `/v1/*` endpoints require `Authorization: Bearer <OPENWA_SERVICE_TOKEN>`. The token must contain at least 32 characters and is compared using a constant-time comparison. Do not put it in URLs, browser code, logs, source control, or the public Laravel frontend. Health endpoints are intentionally unauthenticated and disclose only a generic status. The separate pairing interface is disabled by default (`OPENWA_PAIRING_ENABLED=false`) and uses HTTP Basic authentication over HTTPS only during an explicitly approved pairing window.

### Health

- `GET /health/live`: process liveness; returns `200 {"status":"ok"}`.
- `GET /health/ready`: provider readiness; returns `200 {"status":"ready"}` only when the OpenWA session is connected and authenticated, otherwise `503 {"status":"not_ready"}`.

Use `/health/live` for the Railway process health check. Laravel and deployment monitoring should use `/health/ready` before attempting call-event operations.

### Create event

`POST /v1/call-events`

Headers:

```http
Authorization: Bearer <service-token>
Idempotency-Key: <16-128 characters: letters, numbers, underscore, hyphen>
Content-Type: application/json
```

Body:

```json
{
  "externalBookingReference": "booking_123",
  "destinationPhone": "+40712345678",
  "startAt": "2026-11-06T12:00:00+02:00",
  "endAt": "2026-11-06T12:15:00+02:00",
  "timezone": "Europe/Bucharest",
  "title": "EasyEnglish introductory session",
  "callType": "video"
}
```

Rules:

- Booking reference: 1-100 letters, numbers, underscores, or hyphens.
- Destination: E.164 phone number including country code.
- Start/end: ISO-8601 timestamps with an explicit UTC offset; start must be at least five minutes in the future and end must follow start.
- Maximum event duration: four hours. The application supplies the actual session duration.
- Timezone: valid IANA timezone. It is retained as event metadata; the provider API receives Unix timestamps.
- Title: 1-120 characters.
- Only `video` call events are supported.
- An idempotency key is mandatory. Reusing a key with the same payload replays the original result. Reusing it with a different payload returns `409`.
- A second active event for the same external booking reference returns `409`, even if a different idempotency key is supplied.

Successful response (`201`):

```json
{
  "providerEventId": "<provider-message-id>",
  "joinUrl": "https://call.whatsapp.com/...",
  "status": "scheduled",
  "createdAt": "2026-11-05T10:00:00.000Z"
}
```

A replay returns `200` and adds `"replayed": true`. An uncertain provider result returns `202` with `retryable: false`; do not generate a new key or retry automatically. A provider rejection returns `502`. Invalid input returns `400`, authentication failure `401`, duplicate/conflicting state `409`, and unavailable provider/persistence `503`.

### Retrieve event

`GET /v1/call-events/{providerEventId}` returns the normalized provider ID, join URL, status, and creation timestamp for an event known to this service. Unknown local IDs return `404`; provider failures return `502`.

### Cancel event

`DELETE /v1/call-events/{providerEventId}` returns the normalized cancelled status only after provider cancellation is confirmed. Repeating cancellation for an already cancelled local event is idempotent. Unknown IDs return `404`; unconfirmed/provider failures return `502`.

### Metrics

`GET /v1/metrics` requires the service token and returns aggregate counters only: successfully created events, provider errors, reconnect recoveries, and current connection state. It does not return booking references, phone numbers, event IDs, or join URLs.

## Reliability and storage

- OpenWA 5.10.0 uses compact-session authentication and a disposable Chromium profile. The encrypted checkpoint at `/data/session/session.data.json` is the active WhatsApp credential store; `sessionDataPath` and `sessionDataEncryptionKeyFile` are passed to OpenWA. `userDataDir` is intentionally not used by this version.
- Preserve the checkpoint and its matching encryption key together across deployments and restarts. The checkpoint is backed up before startup without changing its contents. Do not delete it except through the explicit, operator-reviewed pairing reset flow.
- Event/message metadata remains encrypted with AES-256-GCM using `OPENWA_SESSION_ENCRYPTION_KEY`; the separate key directory and session directory retain private permissions. The key encrypts the compact-session credential checkpoint; keep the checkpoint and key together in persistent storage. Directories use `0700`.
- Event metadata, including provider IDs and join URLs needed for idempotency/recovery, is encrypted with AES-256-GCM and written atomically. A corrupt/unreadable encrypted store causes startup to fail closed.
- A `creating` record is persisted before the provider call. If the process restarts during an uncertain submission, the same idempotency key returns a non-retryable pending result instead of creating another event.
- Event creation is serialized in-process and the service must run one replica. Multiple writers are not supported.
- Create calls have a 25-second timeout; retrieve/cancel calls have 10-second timeouts. A create timeout is treated as uncertain and is never retried automatically.
- OpenWA connection-state changes are observed. Readiness drops while disconnected and returns only after the client reports `READY`. Reconnect recovery is counted and logged.
- Logs are structured and exclude phone numbers, booking references, message content, QR values, join URLs, session data, and secrets.
- No provider/browser debugging endpoint is exposed. Do not log raw request bodies or provider objects.

## Configuration

Copy `.env.example` and provide secrets through the deployment platform, not a committed file. Required variables:

- `OPENWA_SESSION_DIR` (encrypted event/message stores and active compact-session checkpoint)
- `OPENWA_SESSION_KEY_DIR` (private key-file directory for the compact-session checkpoint)
- `OPENWA_SESSION_ENCRYPTION_KEY` (base64-encoded 32-byte key for compact-session authentication and encrypted event/message stores)
- `OPENWA_PAIRING_USERNAME`
- `OPENWA_PAIRING_PASSWORD` (at least 32 random characters)
- `OPENWA_PUBLIC_BASE_URL` (HTTPS URL used by the protected pairing interface)
- `OPENWA_SERVICE_TOKEN` (at least 32 random characters, shared only with Laravel; required before live provider startup or API access)
- `OPENWA_ALLOW_LIVE_TEST` (`true` for the currently paired, approved dedicated account)
- `OPENWA_PAIRING_ENABLED` (default `false`; enable only for an approved pairing window, then disable immediately)

`OPENWA_CHROME_EXECUTABLE` and `OPENWA_CHROME_NO_SANDBOX` must match the container runtime. Railway currently requires Chromium's no-sandbox flags in this isolated PoC; the process still runs as the unprivileged `node` user. This weakens browser isolation and is not production approval.

Generate secrets with a cryptographically secure generator, for example:

```sh
openssl rand -base64 32   # session encryption key bytes, then base64 encode exactly once
openssl rand -hex 32      # service token / pairing password material
```

Do not reuse the session encryption key as the service token or pairing password.

## Local development and validation

From `poc/openwa-scheduled-calls`:

```sh
npm ci
npm test
npm audit --audit-level=high
```

The Node built-in test suite uses a fake provider and covers API authentication, health/readiness, request validation, E.164 normalization, timestamps/timezones, create/retrieve/cancel, idempotency conflicts and replay, duplicate booking protection, concurrent duplicate requests, provider errors/timeouts, reconnect readiness transitions, and encrypted persistence/recovery. No WhatsApp credentials or paired account are required for automated tests.

The dependency audit currently reports seven low-severity findings and zero high/critical findings. The remaining prototype-poisoning advisory is in `deepmerge` through `puppeteer-extra`. Do not run `npm audit fix --force`: it proposes downgrading OpenWA to v4.76.0 and invalidates the pinned v5 API validation. Reassess the residual finding before any live account is enabled.

## Deployment and network boundary

Deploy the container from `poc/openwa-scheduled-calls/Dockerfile` as a dedicated Railway service in the separate `easyenglish-openwa` project. This is the selected persistent runtime; Hostinger remains the Laravel host. Attach a persistent volume at `/data`; configure one replica only. Set the service root directory to `/poc/openwa-scheduled-calls` when using the repository root. The image installs Chromium, `zstd`, and runs Node as the unprivileged `node` user.

The Laravel application is hosted outside Railway. Railway private networking and `*.railway.internal` DNS are available only to services in the same Railway project/environment, so Hostinger cannot use the OpenWA private hostname. Keep the Railway HTTPS domain for server-to-server traffic and configure `OPENWA_SERVICE_TOKEN` in Railway and the matching secret in Hostinger. Every `/v1/*` route requires that Bearer token; without it, the API returns `503`. The pairing UI is separately disabled by default through `OPENWA_PAIRING_ENABLED=false`; enable it only for an approved pairing window, keep Basic authentication enabled, and disable it immediately after pairing. Health endpoints disclose only generic liveness/readiness status. Never expose raw OpenWA or browser debugging ports. See [Railway private networking](https://docs.railway.com/networking/private-networking).

Deployment steps:

1. Use the existing `easyenglish-openwa` Railway project, production environment, and `openwa-poc` service. Do not create a second service or replica.
2. Keep the `openwa-session-data` volume mounted at `/data` in `europe-west4-drams3a`; its current capacity is 5 GB.
3. Configure `OPENWA_SESSION_DIR=/data/session`, `OPENWA_SESSION_KEY_DIR=/data/keys`, the event-store and compact-session encryption key, pairing credentials, HTTPS pairing URL, `OPENWA_SERVICE_TOKEN`, Chromium settings, `OPENWA_ALLOW_LIVE_TEST=true`, and `OPENWA_PAIRING_ENABLED=false` after pairing. The `/data/session/session.data.json` file is the active WhatsApp authentication store; retain its matching key in `/data/keys/session-encryption.key`. Store secrets only in Railway Variables.
4. Configure the Railway health check to `GET /health/live`; use restart policy `ALWAYS`; do not enable serverless sleep or multiple replicas. The service serializes event creation and its persistent volume is single-writer.
5. The Docker entrypoint runs `node src/preflight.js` as the unprivileged `node` user and then starts `node src/server.js`. Verify Node, Chromium headless launch, `zstd`, HTTPS configuration, token/key validation, and volume write permissions in deploy logs.
6. Keep `OPENWA_ALLOW_LIVE_TEST=true` for the paired dedicated account. If it is false, the service will not restore an authenticated live session and readiness remains unavailable. If the service token is missing, authenticated `/v1` requests cannot be used. Never disable this flag as routine cleanup while OpenWA is the selected messaging provider.
7. Configure Hostinger Laravel with the public HTTPS Railway base URL and matching service token. Do not use the private Railway hostname from Hostinger or expose either value in client-side code.
8. Pair only the dedicated EasyEnglish account. Temporarily set `OPENWA_PAIRING_ENABLED=true` for an approved pairing/recovery window, confirm readiness, then set it back to `false` and verify `/pairing/status` and `/pairing/qr` return `404`.

**Observed deployment and acceptance state (7 November 2026):** the `openwa-poc` service is deployed in the separate `easyenglish-openwa` Railway project, with one replica, a persistent `/data` volume, and the public HTTPS domain `openwa-poc-production.up.railway.app`. The dedicated WhatsApp account has been paired and a live EasyEnglish verification message has succeeded through Laravel and the OpenWA `/v1/messages` endpoint. Production selects OpenWA as its primary outbound WhatsApp provider. This is an operational acceptance milestone, not a guarantee against future WhatsApp Web changes or account restrictions. Verify current deployment, volume, and backup state in Railway rather than treating this dated observation as a live status assertion.

## Production capacity, backups, and monitoring

The 5 GB Railway volume is a persistent storage mount, not a backup by itself. Railway volume backups are incremental, Copy-on-Write, and encrypted at rest with AES-256. Scheduled retention is 6 days daily, 27 days weekly, and 89 days monthly. A restore stages a new volume and leaves the previous volume unmounted until the new deployment is accepted; wiping a volume also deletes its backups. Restores are limited to the same Railway project and environment, so these backups do not protect against loss/deletion of the whole project. Add an independently stored encrypted backup if cross-project disaster recovery is required. See [Railway volume backups](https://docs.railway.com/volumes/backups), [Railway security and encryption](https://docs.railway.com/enterprise/security), and [volume caveats](https://docs.railway.com/volumes/reference).

### Initial capacity and process model

- Start with one Railway replica in `europe-west4-drams3a`, 1 vCPU and at least 2 GB RAM as a conservative initial allocation. This is a starting envelope, not a measured capacity claim; capture peak CPU, memory, and restart/OOM behavior during #220 and adjust from observed metrics.
- Keep the existing 5 GB persistent volume mounted at `/data`. Review Railway volume usage at least weekly and before each deployment; investigate when usage exceeds 70% capacity.
- Keep exactly one replica. Event creation is serialized in-process and the session store is single-writer. Do not scale horizontally or mount the volume concurrently.
- Keep restart policy `ALWAYS`, the liveness health check `/health/live`, and serverless sleep disabled. Readiness is separate and must remain `503` while unpaired or disconnected.

### Volume backup and restore

Railway supports manual and scheduled volume backups encrypted at rest with AES-256. No schedule has been verified as configured on this environment yet. Configure all three schedules on `openwa-session-data`: daily (6-day retention), weekly (27-day retention), and monthly (89-day retention). These are platform backup windows, not the approved retention period for customer data. Keep `OPENWA_SESSION_ENCRYPTION_KEY` in Railway's secret store and include its controlled recovery procedure with the volume backup; a volume without its matching key is unusable. See [Railway volume backups](https://docs.railway.com/volumes/backups).

A restore creates a new volume and leaves the previous volume unmounted. Restores are restricted to the same Railway project/environment, so add an independently stored encrypted copy if cross-project recovery is required. Before accepting the restore deployment, verify the new volume is mounted at `/data`, the service can decrypt the session/event store, and `/health/live` responds. Do not wipe the previous volume until recovery is confirmed. The first restore drill against an authenticated account must be performed in non-production as part of #220; no real-account restore has been tested yet.

### Application monitoring and alerting

See the [production operations decision record](../decisions/openwa-production-operations.md) for the runtime selection, current deployment state, backup plan, capacity assumptions, rollout stages, and remaining approval gates.

The Laravel scheduler runs `openwa:monitor` every minute. When `OPENWA_ENABLED=true`, it checks `/health/ready`, samples the authenticated `/v1/metrics` counters, and alerts staff if readiness is lost, provider errors reach the configured rolling threshold, or due call-event operations remain stale. It also alerts on terminal notification-delivery failures and a database queue backlog above configured count/age thresholds. Alerts are written to the Filament staff notification inbox, deduplicated for 24 hours, and followed by a recovery notification when the condition clears. Terminal call-event failures already create a staff notification and booking audit record in `BookingCallEventService`. The monitor logs aggregate counts only, never booking references, phone numbers, join URLs, or credentials. The provider-error alert defaults to 5 new errors in 10 minutes; configure thresholds with `OPENWA_MONITOR_PROVIDER_ERROR_THRESHOLD` and `OPENWA_MONITOR_PROVIDER_ERROR_WINDOW_MINUTES`.

The monitor depends on the Hostinger scheduler cron and database queue worker. Confirm `php artisan schedule:list`, the one-minute scheduler invocation, and worker cadence on the actual Hostinger plan. Railway `/health/live` checks process liveness; Laravel monitoring checks `/health/ready`. Use Railway deployment status and restricted deploy logs to investigate container restarts and build/start failures.

### Retention and deletion

The Laravel `booking_call_events.join_url` field is encrypted at rest, hidden from serialization, and exposed only through customer/assigned-teacher booking workflows while the booking is eligible. Confirmed provider cancellation clears the Laravel join URL. The Node event store is encrypted with AES-256-GCM; when an event is cancelled, the service clears the join URL, original request payload (including destination phone), and external booking reference, retaining only a minimal idempotency/cancellation tombstone. Active scheduled events retain the provider metadata and join URL needed to operate the call.

The EasyEnglish retention schedule is still a legal/accounting draft. Do not invent a statutory retention period or enable automatic deletion until the privacy/legal reviewer approves periods and exceptions. Until then, keep `DATA_RETENTION_ENABLED` off, cancel provider events before deleting their Laravel booking records, and handle erasure requests through the approved owner/reviewer process. Backups must expire under the configured Railway schedule and restored data must re-enter the approved deletion process.

## Operations runbook

### Bootstrap and pairing

1. Confirm the correct Railway service, persistent volume, and secret values.
2. Temporarily set `OPENWA_PAIRING_ENABLED=true` and explicitly set `OPENWA_ALLOW_LIVE_TEST=true` only for the dedicated non-production account.
3. Open the HTTPS pairing page with Basic authentication and scan the QR using WhatsApp Linked Devices.
4. Confirm `/health/ready` returns `200`; never copy QR contents into tickets or logs.
5. Set `OPENWA_PAIRING_ENABLED=false` immediately after pairing and verify `/pairing/status` and `/pairing/qr` return `404`. Do not pair personal or primary business accounts.

### Restart and recovery

1. Keep the same `/data` volume attached and preserve the exact encryption key. Run one service replica only.
2. On startup, the service logs `openwa.session.persistence_diagnostics`: a non-reversible key fingerprint plus checkpoint existence, size, modification time, and the path of a private startup backup. The encrypted checkpoint is copied to a new `.startup-backup-<timestamp>.enc` file without overwriting an existing backup.
3. Wait for OpenWA to restore the session and `/health/ready` to return `200`.
4. If a saved checkpoint cannot be restored, the pinned OpenWA 5.10.0 recovery patch stops startup with `SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED`. It must not reset the session, generate a replacement QR, or delete the saved checkpoint automatically. The service logs `openwa.session.restore_failed`.
5. For a restoration failure, preserve the volume and key, compare the logged key fingerprint with a known-good deployment, and inspect the checkpoint metadata and restricted logs. Do not rotate the key, delete the checkpoint, or pair a replacement session as an automatic recovery step.
6. Restore a known-good checkpoint and its matching encryption key only through an operator-reviewed recovery procedure in an isolated service. A new pairing is an explicit destructive operation and must happen only after the old checkpoint and its backups have been preserved.
7. If the encrypted event store cannot be decrypted, keep event creation disabled and restore the matching key/volume backup. Do not create a new empty store over the old one.

### Backup, restore, and key rotation

- Back up the persistent volume and session encryption secret together, using encrypted access-controlled storage.
- Test restore in an isolated non-production service before relying on a backup.
- A session-key rotation requires a controlled migration/re-encryption of session data and event records. Do not simply replace `OPENWA_SESSION_ENCRYPTION_KEY`; doing so makes existing encrypted data unreadable.
- Keep backup retention aligned with the EasyEnglish privacy and retention policies. The event store contains personal data and join URLs.

### Emergency disable and account disconnect

1. Set Laravel `OPENWA_ENABLED=false` to stop new booking call-event operations and preserve existing booking records/issued links.
2. Set Node `OPENWA_ALLOW_LIVE_TEST=false` and restart the service. Readiness must become `503` and event operations must return `503`.
3. Set `OPENWA_PAIRING_ENABLED=false` and revoke the linked device from the dedicated WhatsApp account.
4. Keep the public HTTPS domain only while Hostinger needs the authenticated API; pairing endpoints must return `404`. Rotate the service token if compromise is suspected.
5. Preserve encrypted records for incident investigation only as required by the retention policy; otherwise securely delete the volume and secrets.
6. Do not re-enable the service without explicit approval after the incident is reviewed.

## Staged rollout and rollback

The rollout has two independent controls: `OPENWA_ENABLED` is the hard application kill switch, while `OPENWA_ROLLOUT_STAGE` controls which bookings may create new events. The rollout stage defaults to `off`; paid sessions remain excluded unless `OPENWA_APPLY_TO_PAID_SESSIONS=true` is separately approved.

| Stage | Configuration | Eligible bookings |
| --- | --- | --- |
| Internal test | `OPENWA_ENABLED=true`, `OPENWA_ROLLOUT_STAGE=internal_test`, `OPENWA_ROLLOUT_CUSTOMER_EMAILS` set | Only the explicitly allowlisted test customer emails |
| Staff pilot | `OPENWA_ROLLOUT_STAGE=staff_pilot` | Only bookings whose customer email belongs to an `is_staff` account |
| Limited customer pilot | `OPENWA_ROLLOUT_STAGE=limited_customer`, `OPENWA_ROLLOUT_CUSTOMER_EMAILS` set | Only explicitly allowlisted customer emails |
| General | `OPENWA_ROLLOUT_STAGE=general` | All otherwise eligible confirmed bookings |

At each stage, verify readiness, event creation/cancellation, current-revision link access, customer/teacher notifications, delivery retries, error-rate alerts, and manual fallback before expanding. The allowlist is parsed and validated at application boot. `internal_test` and `limited_customer` fail configuration if the allowlist is empty. Never include the allowlist in logs.

**Rollback:** set `OPENWA_ROLLOUT_STAGE=off` first. This prevents new events but leaves existing confirmed bookings and issued links intact; queued creation jobs become no-ops, while existing events can still be reconciled or cancelled. Do not delete bookings, event rows, or links as part of rollback. Use `OPENWA_ENABLED=false` only as a hard stop when provider operations themselves must cease; this also prevents cancellation/reconciliation requests, so it is not the normal pilot rollback. Keep the Node provider running until existing calls are completed or cancelled. The feature-flag tests cover allowlisted internal/limited stages, staff-only pilot eligibility, general mode, and rollback without deleting existing links.

## Final acceptance boundary

The implementation issues establish the isolated service, API contract, fake-provider tests, authentication, encrypted persistence, idempotency, lifecycle/readiness handling, and operational documentation. The assembled Laravel flow now redirects confirmed bookings to the authenticated dashboard and exposes pending/ready/failed call-link status without embedding the join URL. This still does not establish that real WhatsApp accounts can reliably join the same scheduled event on Android/iOS.

The dedicated account has been paired and a live transactional verification message has succeeded. Remaining acceptance work includes restart/reconnect recovery, customer/teacher Android/iOS join testing, backup restore, inbound opt-out, transactional notification coverage, and final review of the rollout scope. The traceable automated/manual matrix is maintained in [public booking journey acceptance](../testing/public-booking-journey-acceptance.md).

## Laravel integration

The Laravel application uses a provider-neutral `CallEventProvider` contract. `OpenWaCallEventProvider` is the production adapter and `OpenWaHttpClient` is the only class that knows the Node service's HTTP contract. Booking orchestration and queued jobs depend on the provider contract, not on OpenWA request/response details. Tests replace the provider with a fake implementation and use Laravel's HTTP fake for transport-level contract tests.

### Application configuration

Configure the following values only in the application environment, never in source control. The call-event rollout flag remains independent of outbound messaging:

- `OPENWA_ENABLED=false` (leave false until the rollout gate approves it)
- `OPENWA_APPLY_TO_PAID_SESSIONS=false` (paid bundle-credit sessions are excluded by default; free introductions and bundle-included free credits are included)
- `OPENWA_ROLLOUT_STAGE=off` (default; use `internal_test`, `staff_pilot`, `limited_customer`, or `general` only after the preceding stage passes)
- `OPENWA_ROLLOUT_CUSTOMER_EMAILS` (comma-separated, validated allowlist required for `internal_test` and `limited_customer`)
- `OPENWA_BASE_URL=https://openwa-poc-production.up.railway.app` (public HTTPS domain; Laravel is on Hostinger and cannot resolve Railway private DNS)
- `OPENWA_SERVICE_TOKEN` (the same high-entropy token configured as `OPENWA_SERVICE_TOKEN` in the Node service; at least 32 characters)
- `OPENWA_CONNECT_TIMEOUT=5`
- `OPENWA_TIMEOUT=30` (must exceed the Node create-operation timeout)
- `OPENWA_MAX_ATTEMPTS=5`
- `OPENWA_RETRY_DELAYS=60,300,900,3600,14400` (seconds)

The typed `OpenWaConfig` validates the URL scheme, token length, timeout bounds, retry count, and retry schedule at container resolution. The Hostinger runtime must use the public HTTPS Railway domain. Plain HTTP to `.railway.internal` is supported only if Laravel is moved into the same Railway project/environment. URL credentials, query strings, and fragments are rejected. The service token is sent only in the Bearer authorization header and is never logged.

The Node service exposes separate `/health/live` and `/health/ready` endpoints. Hosting-platform process health checks must use `/health/live`, which stays successful while the service is safely unpaired. Laravel and operational monitoring use `/health/ready`; it remains unavailable until the WhatsApp session is authenticated and the provider is initialized. The pairing UI is independently gated by `OPENWA_PAIRING_ENABLED=false` and returns `404` when disabled. Do not treat an unpaired service as ready, and do not use readiness as the container liveness check.

`OPENWA_ENABLED` is the Laravel feature flag. It is separate from the Node service's `OPENWA_ALLOW_LIVE_TEST` runtime guard. Both must be explicitly enabled before live event operations can occur. With the Laravel feature flag disabled, event jobs do not create records or make HTTP requests. Booking confirmation always remains authoritative. Free introductory sessions, including the free credit included in a bundle, are eligible. Paid bundle-credit sessions are excluded unless `OPENWA_APPLY_TO_PAID_SESSIONS=true` is explicitly configured.

### Persistence and idempotency

`booking_call_events` stores one provider event per booking/provider/revision. Database unique constraints enforce the booking/provider/revision tuple, provider event ID, and idempotency key. The key is deterministic from the booking public reference and revision, so retries and duplicate queue delivery reuse the same provider operation.

The record stores provider event ID, call type, UTC start/end, normalized status, attempt timestamps, retry scheduling, sanitized error category/message, and provider lifecycle timestamps. The join URL uses Laravel's encrypted cast and is hidden from model serialization. Do not add it to general booking API resources, logs, audit metadata, or notification-delivery payloads. A cancellation clears the stored join URL after the provider confirms cancellation.

The event row snapshots the assigned teacher ID so a reassignment invalidates the previous revision's call-link endpoint access. The Laravel event row is deleted when its booking is deleted. Otherwise it follows the booking record's retention policy; this integration does not introduce a separate approved retention period. The encrypted Node event store clears the join URL, original request payload, and external booking reference when cancellation is confirmed, retaining only a minimal idempotency/cancellation tombstone. Active events retain the provider metadata required to operate the call. Do not manually purge an event while its provider call may still be active. Cancel it first. The encrypted URL remains personal data and must be covered by the same access, backup, and deletion controls as booking data.

### Queued operations and retry behavior

- `CreateBookingCallEvent` creates the provider event for a confirmed, teacher-assigned booking and a specific revision.
- `RetrieveBookingCallEvent` reconciles an event when the provider has accepted a create request but has not yet returned a usable join URL.
- `CancelBookingCallEvent` cancels an existing provider event and removes the stored join URL after confirmation.
- `call-events:dispatch` queues due retries and reconciliations. It is scheduled every minute and uses a database operation lease so overlapping scheduler runs do not queue the same row repeatedly.
- Create retries always reuse the original idempotency key. An HTTP timeout is treated as an uncertain outcome, not proof that no event was created. The same key is retried or reconciled; a new key is never generated for a retry.
- Retryable transport/provider failures use the configured bounded backoff. Authentication, invalid request, invalid response, and provider-not-found failures are terminal and visible on the event record for staff follow-up.
- A stale in-progress operation is returned to the appropriate pending/reconciliation state after its lease expires. Cancellation requested while creation is in flight is preserved and applied as soon as the provider event is recovered.

Run the dispatcher with the normal Laravel scheduler and queue worker. The command can be run manually with `php artisan call-events:dispatch`; it is a no-op while `OPENWA_ENABLED=false`.

### Authorized call-link retrieval

The authenticated `GET /booking/{reference}/call-link` route is the only application endpoint that returns the join URL. Access is authorized by `BookingPolicy::viewCallLink`:

- The customer account email must be verified and match the booking's customer email, case-insensitively.
- The teacher must have a verified email, be the currently assigned teacher on the booking, and have the teacher role.
- Staff accounts do not receive call-link access through this customer/teacher endpoint.

The route requires the booking to remain confirmed, and verifies that the latest event revision still matches the booking's assigned teacher and current UTC start/end. It returns a link only when that revision is scheduled with a non-empty URL. It never falls back to an older revision while a reschedule is pending or has failed. It requires authentication, is rate-limited, and sends `Cache-Control: private, no-store` and `Referrer-Policy: no-referrer`. The public confirmation page and signed booking-management URL do not expose the join URL. The link is not returned by general booking model serialization.

### Booking lifecycle and recipient notifications

BookingService queues `CreateBookingCallEvent` only after the booking transaction commits and only when the booking is confirmed and assigned to a teacher. Pending holds, unverified registrations, bookings without an eligible teacher, and paid bundle-credit sessions when `OPENWA_APPLY_TO_PAID_SESSIONS=false` do not create call events. Free introductions and bundle-included free credits use the same lifecycle. A provider outage never rolls back the booking confirmation.

The event is associated with `reschedule_count + 1`. On reschedule, the previous revision is cancelled and the replacement revision is created with a new deterministic idempotency key. If the teacher changes, the previous teacher receives a reassignment/cancellation notice and the new teacher receives the replacement call details. Customer cancellation and teacher cancellation queue provider cancellation; both participants receive the appropriate existing customer notification and teacher-only cancellation notification. A creation already in flight records cancellation intent and is cancelled as soon as the provider event is recovered. Old revisions never remain accessible through the application link endpoints.

When the provider confirms a valid event, Laravel creates separate notification outbox records for the customer and assigned teacher. Email and WhatsApp are separate channels and each recipient/channel has an independent idempotency key and retry state. The same verified join URL is read from the encrypted call-event record at send time; it is not copied into notification metadata. Customer messages use the booking locale (`ro` or `en`) and include Bucharest date/time, duration, timezone, and join instructions. Teachers receive a confirmation message when assigned, a reschedule message whenever the time changes, and a separate call-link message when the provider is ready. Teacher messages are in English and include customer contact details, English level, session goal, optional notes, appointment time, and the authenticated internal booking/feedback page; the call-link message additionally includes the join URL. If teacher and customer share a phone number, teacher-only WhatsApp content is not sent to that number; the teacher receives email instead.

The following Meta templates must exist and be approved before enabling WhatsApp call-link notifications. Laravel sends one body component with text parameters in this exact order:

| Template key | Default Meta template name | Body parameters |
|---|---|---:|
| `teacher_booking_confirmed` | `easyenglish_teacher_booking_confirmation` | 12 |
| `teacher_booking_rescheduled` | `easyenglish_teacher_booking_rescheduled` | 11 |
| `customer_call_link_ready` | `easyenglish_call_link_ready` | 7 |
| `teacher_call_link_ready` | `easyenglish_teacher_call_link_ready` | 13 |
| `teacher_booking_cancelled` | `easyenglish_teacher_booking_cancelled` | 4 |

Teacher booking-confirmation parameters are teacher name, customer name/email/phone, English level, session goal, notes, local date, local time, duration, timezone, and internal booking URL. Teacher reschedule parameters are teacher name, customer name/email/phone, previous local date/time, new local date/time, duration, timezone, and internal booking URL. The customer template parameters are name, local date, local time, duration, timezone, join URL, and join instructions. Teacher call-ready parameters are teacher name, customer name/email/phone, English level, session goal, notes, local date/time, duration, timezone, join URL, and internal booking URL. Teacher cancellation parameters are teacher name, customer name, original appointment time, and cancellation/reassignment reason. Configure alternate names with the corresponding `WHATSAPP_TEMPLATE_*` environment variables. The application feature flag does not create or approve Meta templates.

`/teacher/bookings/{reference}` is protected by authentication and the teacher middleware and returns a booking only to the currently assigned teacher. It provides session details and a feedback form after the confirmed session has ended. Feedback is saved per metric and as an overall record. The teacher page checks the latest event revision, teacher assignment, and UTC schedule before showing a call link.

Staff can inspect event state, retry exhausted event setup, and review sanitized errors at **Admin → Scheduling → Call events**. Exhausted automatic retries create a staff database notification and a booking audit record. Customer/teacher message delivery is tracked separately at **Admin → Operations → Notification delivery**; a failed recipient delivery can be retried without creating another provider event. The call-event retry action reuses the original idempotency key and chooses create, retrieve, or cancel according to the persisted provider state.

`OPENWA_ENABLED` is a hard stop for all provider operations, including cancellation and reconciliation. Do not disable it while provider events are still active; cancel or resolve active events first. Keep `OPENWA_ENABLED` aligned with the separately approved scheduled-call rollout stage; it is not the messaging switch. OpenWA messaging remains enabled as the primary outbound WhatsApp provider. No customer traffic or real WhatsApp account is enabled by this integration change.

## Transactional messaging capability (separate from scheduled calls)

The isolated OpenWA service is the selected primary Easy English outbound WhatsApp messaging provider. Messaging remains a separate capability from scheduled call-event creation, with independent feature flags and readiness checks.

- Keep `OPENWA_MESSAGING_ENABLED=true` on both the Railway service and the Hostinger Laravel application. The Node service also requires `OPENWA_ALLOW_LIVE_TEST=true` for the paired live account. These are current production settings; do not disable them during routine deployments.
- Set `OPENWA_MESSAGING_CALLBACK_URL=https://<easyenglish-host>/webhooks/openwa` and a high-entropy `OPENWA_MESSAGING_CALLBACK_SECRET` on Railway. Configure the same callback secret on Hostinger. The callback uses HMAC-SHA256 in `X-OpenWA-Signature`.
- Laravel uses the existing `OPENWA_BASE_URL` and `OPENWA_SERVICE_TOKEN` for the authenticated `/v1/messages` API. The API requires an E.164 recipient, text body, and `Idempotency-Key`.
- The Node service persists outbound idempotency records and inbound callback records in the encrypted message store on the existing persistent volume. It serializes outbound submissions, returns accepted/replayed/ambiguous states, and never blindly retries an uncertain submission.
- `GET /v1/messages/{idempotencyKey}` exposes only the normalized state, provider message ID, attempt count, and acceptance timestamp for reconciliation. `GET /v1/capabilities` reports whether messaging is enabled and ready.
- Inbound direct messages are normalized and forwarded to Laravel; group and messages sent by the business account are ignored. Message payloads are encrypted at rest and removed from the record after a successful callback; logs contain no phone number or message body.
- Keep exactly one replica and the existing persistent volume. The message store is single-writer, just like the scheduled call-event store.

Do not interpret OpenWA readiness as proof that the WhatsApp account is unrestricted. OpenWA is unofficial automation and remains subject to account restrictions and upstream web-client changes. See the [provider failover runbook](../operations/whatsapp-provider-failover.md) for the business-continuity procedure.
## Current production provider policy

`WHATSAPP_PROVIDER=openwa` is the intended production selection. On Hostinger, keep `OPENWA_MESSAGING_ENABLED=true`, `OPENWA_BASE_URL` pointed at the Railway public HTTPS domain, and the matching service token and callback secret configured as secrets. On Railway, keep `OPENWA_MESSAGING_ENABLED=true`, `OPENWA_ALLOW_LIVE_TEST=true`, the callback URL/secret, service token, session encryption key, and persistent `/data` volume. Never commit secret values. After changing Laravel environment values, run `php artisan config:cache` and `php artisan queue:restart`, then verify `php artisan whatsapp:provider-status` reports OpenWA selected and ready. The authenticated `/v1/capabilities` endpoint must report `provider=openwa`, `messagingEnabled=true`, `messagingReady=true`, and `connectionState=authenticated` before sending.

The service must remain single-replica because the WhatsApp session and encrypted message store are single-writer. Preserve the `/data` volume, especially `/data/session/session.data.json`, across deploys and restarts. The matching encryption key must remain unchanged for both compact-session authentication and encrypted event/message records. OpenWA 5.10.0 uses the compact session file, not a persistent Chromium profile. A missing/unreadable compact-session checkpoint must be treated as not ready; a successful container deployment does not mean the WhatsApp account is authenticated. Pairing is a recovery action, not a routine deployment step.

Meta remains available as a manual fallback. To switch, set `WHATSAPP_PROVIDER=meta` in Hostinger, confirm Meta configuration and templates remain valid, run `php artisan config:cache` and `php artisan queue:restart`, and verify provider status before sending. Reconcile ambiguous OpenWA deliveries first; never blindly replay them through Meta. See the [provider continuity runbook](../operations/whatsapp-provider-failover.md).
