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

## Laravel integration

The Laravel application uses a provider-neutral `CallEventProvider` contract. `OpenWaCallEventProvider` is the production adapter and `OpenWaHttpClient` is the only class that knows the Node service's HTTP contract. Booking orchestration and queued jobs depend on the provider contract, not on OpenWA request/response details. Tests replace the provider with a fake implementation and use Laravel's HTTP fake for transport-level contract tests.

### Application configuration

The Laravel integration is disabled by default. Configure the following values only in the application environment, never in source control:

- `OPENWA_ENABLED=false` (leave false until the rollout gate approves it)
- `OPENWA_APPLY_TO_PAID_SESSIONS=false` (paid bundle-credit sessions are excluded by default; free introductions and bundle-included free credits are included)
- `OPENWA_BASE_URL=https://...` or the private Railway hostname, for example `http://openwa-poc.railway.internal:8080`
- `OPENWA_SERVICE_TOKEN` (the same high-entropy token configured as `OPENWA_SERVICE_TOKEN` in the Node service; at least 32 characters)
- `OPENWA_CONNECT_TIMEOUT=5`
- `OPENWA_TIMEOUT=30` (must exceed the Node create-operation timeout)
- `OPENWA_MAX_ATTEMPTS=5`
- `OPENWA_RETRY_DELAYS=60,300,900,3600,14400` (seconds)

The typed `OpenWaConfig` validates the URL scheme, private Railway hostname, token length, timeout bounds, retry count, and retry schedule at container resolution. Public endpoints must use HTTPS. Plain HTTP is accepted only for a `.railway.internal` host. URL credentials, query strings, and fragments are rejected. The service token is sent only in the Bearer authorization header and is never logged.

The Node service exposes separate `/health/live` and `/health/ready` endpoints. Hosting-platform process health checks must use `/health/live`, which stays successful while the service is safely unpaired. Laravel and operational monitoring use `/health/ready`; it remains unavailable until the WhatsApp session is authenticated and the provider is initialized. Do not treat an unpaired service as ready, and do not use readiness as the container liveness check.

`OPENWA_ENABLED` is the Laravel feature flag. It is separate from the Node service's `OPENWA_ALLOW_LIVE_TEST` runtime guard. Both must be explicitly enabled before live event operations can occur. With the Laravel feature flag disabled, event jobs do not create records or make HTTP requests. Booking confirmation always remains authoritative. Free introductory sessions, including the free credit included in a bundle, are eligible. Paid bundle-credit sessions are excluded unless `OPENWA_APPLY_TO_PAID_SESSIONS=true` is explicitly configured.

### Persistence and idempotency

`booking_call_events` stores one provider event per booking/provider/revision. Database unique constraints enforce the booking/provider/revision tuple, provider event ID, and idempotency key. The key is deterministic from the booking public reference and revision, so retries and duplicate queue delivery reuse the same provider operation.

The record stores provider event ID, call type, UTC start/end, normalized status, attempt timestamps, retry scheduling, sanitized error category/message, and provider lifecycle timestamps. The join URL uses Laravel's encrypted cast and is hidden from model serialization. Do not add it to general booking API resources, logs, audit metadata, or notification-delivery payloads. A cancellation clears the stored join URL after the provider confirms cancellation.

The event row snapshots the assigned teacher ID so a reassignment invalidates the previous revision's call-link endpoint access. The event row is deleted when its booking is deleted. Otherwise it follows the booking record's retention policy; this integration does not introduce a separate retention period. Do not manually purge an event while its provider call may still be active. Cancel it first. The encrypted URL remains personal data and must be covered by the same access, backup, and deletion controls as booking data.

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

`OPENWA_ENABLED` is a hard stop for all provider operations, including cancellation and reconciliation. Do not disable it while provider events are still active; cancel or resolve active events first. Keep `OPENWA_ENABLED=false` until the assembled booking and notification flow passes #220 and the product owner explicitly approves rollout. No customer traffic or real WhatsApp account is enabled by this integration change.
