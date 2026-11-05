# OpenWA scheduled-call service

## Purpose and architecture

OpenWA is isolated in a standalone Node.js service. Laravel remains the system of record for users, bookings, teacher assignment, payment state, and notification delivery. Laravel calls this service over a private service-to-service API; it must never connect to the OpenWA browser or raw client directly.

The existing Meta WhatsApp Business Platform integration remains the approved channel for transactional messages. OpenWA is an unofficial WhatsApp Web automation package used only to create, retrieve, and cancel scheduled WhatsApp video-call events. It is not an approved replacement for Meta messaging. Its use may conflict with WhatsApp terms and may result in account restrictions. Use a dedicated disposable non-production WhatsApp account only.

The service owns one OpenWA session and one durable encrypted event store. Run exactly one replica. Do not embed OpenWA, Chromium, or browser automation in the Laravel runtime. The service is deployed separately from the EasyEnglish website because the confirmed Hostinger Cloud Startup plan does not support the persistent browser process and separate services required by OpenWA.

## Provider contract

- Package: `@open-wa/wa-automate@5.10.0` (pinned)
- Node.js: `22.21.1` or newer
- Create: `client.sendEvent(chatId, event)`
- Retrieve: `client.getEvent(providerEventId)`
- Cancel: `client.cancelEvent(providerEventId)`
- Event timestamps: Unix seconds
- Supported event type: scheduled video call

The installed v5.10.0 declarations and PoC verify that `sendEvent` returns a serialized WhatsApp message ID accepted by `getEvent`; a retrieved event can expose `joinLink` and `isCanceled`. Event creation and URL availability are separate operations. The adapter polls retrieval for up to six attempts. If creation or retrieval has an uncertain outcome, the service records that state and refuses automatic resubmission.

The provider receives a destination E.164 phone number, which is converted to the OpenWA chat ID. The same returned join URL is then available to Laravel so Laravel can deliver it to the teacher using the approved messaging workflow. The service does not send teacher/customer message content.

## HTTP API v1

All `/v1/*` endpoints require `Authorization: Bearer <OPENWA_SERVICE_TOKEN>`. The token must contain at least 32 characters and is compared using a constant-time comparison. Do not put it in URLs, browser code, logs, source control, or the public Laravel frontend. Health endpoints are intentionally unauthenticated and disclose only a generic status. The separate pairing interface uses HTTP Basic authentication over HTTPS.

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

- Session data and event metadata are stored on the persistent Railway volume, outside the application directory and public web root.
- Session data and the encryption-key directory are separate and have private directory permissions. The session encryption key is supplied as a Railway secret and written with file mode `0600`; directories use `0700`.
- Event metadata, including provider IDs and join URLs needed for idempotency/recovery, is encrypted with AES-256-GCM and written atomically. A corrupt/unreadable encrypted store causes startup to fail closed.
- A `creating` record is persisted before the provider call. If the process restarts during an uncertain submission, the same idempotency key returns a non-retryable pending result instead of creating another event.
- Event creation is serialized in-process and the service must run one replica. Multiple writers are not supported.
- Create calls have a 25-second timeout; retrieve/cancel calls have 10-second timeouts. A create timeout is treated as uncertain and is never retried automatically.
- OpenWA connection-state changes are observed. Readiness drops while disconnected and returns only after the client reports `READY`. Reconnect recovery is counted and logged.
- Logs are structured and exclude phone numbers, booking references, message content, QR values, join URLs, session data, and secrets.
- No provider/browser debugging endpoint is exposed. Do not log raw request bodies or provider objects.

## Configuration

Copy `.env.example` and provide secrets through the deployment platform, not a committed file. Required variables:

- `OPENWA_SESSION_DIR`
- `OPENWA_SESSION_KEY_DIR`
- `OPENWA_SESSION_ENCRYPTION_KEY` (base64-encoded 32-byte key)
- `OPENWA_PAIRING_USERNAME`
- `OPENWA_PAIRING_PASSWORD` (at least 32 random characters)
- `OPENWA_PUBLIC_BASE_URL` (HTTPS URL used by the protected pairing interface)
- `OPENWA_SERVICE_TOKEN` (at least 32 random characters, shared only with Laravel; required before live provider startup or API access)
- `OPENWA_ALLOW_LIVE_TEST` (keep `false` until the final acceptance gate)

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

Deploy the container from `poc/openwa-scheduled-calls/Dockerfile` as a dedicated Railway service in the separate `easyenglish-openwa` project. Attach a persistent volume at `/data`; configure one replica only. Set the service root directory to `/poc/openwa-scheduled-calls` when using the repository root. The image installs Chromium, `zstd`, and runs Node as the unprivileged `node` user.

Use Railway private networking for Laravel-to-OpenWA API traffic. A public HTTPS domain may be enabled temporarily for the protected pairing interface; because the service API shares the same listener, the bearer token remains mandatory during this window. Remove the public domain/ingress after pairing and use the private Railway hostname for Laravel-to-OpenWA traffic. Never expose raw OpenWA or browser debugging ports. The bearer token remains mandatory even on the private network.

Deployment steps:

1. Create the dedicated Railway project/environment and a single `openwa-service` service using this Dockerfile.
2. Attach a persistent volume at `/data` in the same region.
3. Configure `OPENWA_SESSION_DIR=/data/session`, `OPENWA_SESSION_KEY_DIR=/data/keys`, the session encryption key, pairing credentials, HTTPS pairing URL, service token, Chromium settings, and `OPENWA_ALLOW_LIVE_TEST=false`.
4. Configure the Railway health check to `GET /health/live`; use restart policy `ALWAYS`; do not enable serverless sleep or multiple replicas.
5. Run `npm run preflight` inside the deployed container as the unprivileged `node` user. Verify Node, Chromium headless launch, `zstd`, HTTPS configuration, token/key validation, and volume write permissions.
6. Keep `OPENWA_ALLOW_LIVE_TEST=false` until the final acceptance issue explicitly authorizes pairing. In this mode the process and liveness endpoint work, while readiness correctly remains `503`. If the service token is not configured, all `/v1` requests return `503` and no API operation is available. Configure the token before Laravel integration or enabling the live provider.
7. Configure Laravel with the private service base URL and the matching service token. Do not expose either value in client-side code.
8. Pair only the dedicated non-production account when authorized by final acceptance. Confirm readiness before exercising the API.

The existing Railway PoC deployment was previously observed running, but its deployed-container preflight output was not verified. The service API implementation in this issue must not be described as deployed or production-ready until a separate deployment check confirms the new image, environment variables, health checks, and durable volume.

## Operations runbook

### Bootstrap and pairing

1. Confirm the correct Railway service, persistent volume, and secret values.
2. Temporarily enable the protected pairing interface and explicitly set `OPENWA_ALLOW_LIVE_TEST=true` only for the dedicated non-production account.
3. Open the HTTPS pairing page with Basic authentication and scan the QR using WhatsApp Linked Devices.
4. Confirm `/health/ready` returns `200`; never copy QR contents into tickets or logs.
5. Disable public pairing access when no longer needed. Do not pair personal or primary business accounts.

### Restart and recovery

1. Keep the same `/data` volume attached and preserve the encryption key.
2. Restart one service replica only.
3. Wait for OpenWA to restore the session and `/health/ready` to return `200`.
4. If readiness does not recover, inspect restricted service logs and the connection-state metric. Do not delete session files or rotate the encryption key as a first response.
5. If the encrypted event store cannot be decrypted, keep event creation disabled and restore the matching key/volume backup. Do not create a new empty store over the old one.

### Backup, restore, and key rotation

- Back up the persistent volume and session encryption secret together, using encrypted access-controlled storage.
- Test restore in an isolated non-production service before relying on a backup.
- A session-key rotation requires a controlled migration/re-encryption of session data and event records. Do not simply replace `OPENWA_SESSION_ENCRYPTION_KEY`; doing so makes existing encrypted data unreadable.
- Keep backup retention aligned with the EasyEnglish privacy and retention policies. The event store contains personal data and join URLs.

### Emergency disable and account disconnect

1. Set `OPENWA_ALLOW_LIVE_TEST=false` and restart the service. Readiness must become `503` and event operations must return `503`.
2. Revoke the linked device from the dedicated WhatsApp account.
3. Remove public pairing ingress and rotate the service token if compromise is suspected.
4. Preserve encrypted records for incident investigation only as required by the retention policy; otherwise securely delete the volume and secrets.
5. Do not re-enable the service without explicit approval after the incident is reviewed.

## Final acceptance boundary

This issue implements the isolated service, API contract, fake-provider tests, authentication, encrypted persistence, idempotency, lifecycle/readiness handling, and operational documentation. It does not establish that real WhatsApp accounts can reliably join the same scheduled event on Android/iOS or that the assembled Laravel booking flow is ready.

Real-account pairing, restart/reconnect acceptance against an authenticated account, customer/teacher join testing, assembled booking-to-notification validation, final GO/NO-GO, and production enablement remain deferred to issue #220. Keep `OPENWA_ALLOW_LIVE_TEST=false` and do not send production traffic before #220 passes and the product owner explicitly approves rollout.
