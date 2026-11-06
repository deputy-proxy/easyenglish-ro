# ADR: OpenWA production runtime and operations

- **Status:** Selected architecture, production rollout not approved
- **Date:** 5 November 2026
- **Related:** #209, #210, #214, #215, #216, #220

## Context

EasyEnglish Laravel runs on Hostinger Cloud Startup. That environment is suitable for the PHP application, database-backed queues, and cron-driven scheduler, but it is not the selected host for a persistent Chromium/WhatsApp Web process with durable browser-session storage. OpenWA must remain isolated from Laravel and from the official Meta WhatsApp Cloud API used for transactional messages and verification.

## Decision

Run OpenWA as a dedicated Docker service in the existing Railway `easyenglish-openwa` project, production environment, `openwa-poc` service. Keep one replica in `europe-west4-drams3a` with the `openwa-session-data` 5 GB volume mounted at `/data`. Use Railway restart policy `ALWAYS`, disable sleep, and use `GET /health/live` as the container health check. The Docker entrypoint runs preflight as the unprivileged `node` user, then starts the HTTP server. A single replica is required because the encrypted session/event store and in-process event serialization are single-writer.

The current Laravel host is outside Railway. Railway private DNS (`*.railway.internal`) is scoped to services in the same Railway project/environment and is not reachable from Hostinger. Therefore Laravel calls the Railway HTTPS domain using a mandatory high-entropy Bearer token. All `/v1/*` routes require the token. Only generic liveness/readiness endpoints are unauthenticated. The pairing UI is disabled by default with `OPENWA_PAIRING_ENABLED=false`; a temporary Basic-authenticated pairing window is permitted only for the dedicated non-production account and must be closed immediately afterward.

Secrets are configured through Railway Variables and Hostinger environment variables, never committed to Git or copied into the Docker image. The session directory and encrypted event store live on `/data`, outside the image. The session encryption key is stored in Railway's secret store and must be preserved with the backup/recovery procedure.

## Runtime capacity and observed state

Railway watches `deputy-proxy/easyenglish-ro` on `main`, builds the Dockerfile from `/poc/openwa-scheduled-calls`, and checks `/health/live`. GitHub Actions runs the OpenWA PoC tests and dependency audit before merge. The #216 entrypoint fix normalizes the legacy start-command override and runs preflight once before starting the HTTP server.

The connected Railway environment currently reports the `openwa-poc` service online with one replica, a 5 GB volume, and the `europe-west4-drams3a` region. The latest deployment reports success. No explicit CPU/memory limits are configured. A 24-hour sample on 5 November 2026, while unpaired, showed 0.331 GB peak memory and 0.12 GB disk usage, with negligible CPU/network activity. This is not an authenticated-session or production-load baseline. Use 1 vCPU and 2 GB RAM as the initial capacity target, then measure during #220 and adjust using Railway service metrics. Review volume usage weekly and investigate above 70%.

Railway volume backups are encrypted at rest with AES-256 and should be enabled daily (6-day retention), weekly (27-day retention), and monthly (89-day retention). A restore creates a new volume and leaves the previous volume unmounted. Restores are restricted to the same Railway project/environment, so they are not an independent cross-project disaster-recovery copy. The schedule is not verified as configured in the connected environment, and an authenticated-session restore drill has not yet been performed. Complete both as part of #220. Add a separately stored encrypted backup if cross-project recovery is required. See [Railway volume backups](https://docs.railway.com/volumes/backups), [Railway encryption at rest](https://docs.railway.com/enterprise/security), and [volume behavior](https://docs.railway.com/volumes/reference).

## Security and current blockers

The public domain `openwa-poc-production.up.railway.app` is required for Hostinger-to-Railway HTTPS calls. The current Railway variable inventory does not contain `OPENWA_SERVICE_TOKEN`, so the public `/v1` API remains fail-closed and unavailable to Laravel. `OPENWA_PAIRING_ENABLED` is absent and therefore remains off by default. The latest deployment is online and reports success; this verifies container liveness, not authenticated readiness or Laravel integration. The connected project does not document or provision a separate staging Laravel endpoint/token pair.

No WhatsApp account is paired and no live call events have been created. Keep Laravel `OPENWA_ENABLED=false` until #220's staging/device gates and explicit product/operations approval pass. The Railway environment is named `production` even though the OpenWA service is isolated from the Hostinger Laravel application and intended for a dedicated non-production account; do not treat it as a staging environment. No raw browser debugging port is exposed. Outbound traffic requires DNS and HTTPS/WSS to WhatsApp Web and required provider endpoints; inbound traffic is limited to the Railway HTTPS listener. Health endpoints return generic status only. The public `/v1` API is bearer-authenticated and fails closed when the service token is absent.

OpenWA relies on unofficial WhatsApp Web automation rather than the official Cloud API. This carries account restriction and compatibility risk. The owner must review the provider's current terms, Railway acceptable-use terms, and account risk before live use. No legal/terms approval is recorded here.

## Monitoring and incident response

Laravel schedules `openwa:monitor` every minute. While the rollout stage is active it checks readiness, authenticated provider metrics, and stale due call-event operations. It also checks terminal notification delivery failures and database queue pressure. The alert thresholds default to five provider errors in ten minutes, 20 minutes for stale call-event operations, 30 minutes for delivery failures, 50 queued jobs, or an oldest queued job age of ten minutes. Staff receive deduplicated Filament database notifications and recovery notices. Terminal event-creation failures independently create a staff alert and booking audit entry.

Structured application logs contain aggregate counts and operational state only. Do not log booking references, phone numbers, join URLs, QR payloads, session contents, or secrets. Railway logs and metrics are restricted to operators. Railway log retention depends on the workspace plan; export only sanitized operational signals if longer retention is required.

If a confirmed booking has no ready link 30 minutes before the appointment (or immediately for a booking inside that window), staff contact the learner and assigned teacher and arrange a normal WhatsApp call manually. Do not cancel the booking or reuse another booking's link. Record the fallback in the booking audit trail.

## Retention and deletion

Laravel stores join URLs encrypted at rest and only exposes the current booking revision to the customer and assigned teacher. Cancellation clears the Laravel join URL. The encrypted Node event store clears the join URL, original request payload (including destination phone), and external booking reference when an event is cancelled, retaining a minimal idempotency/cancellation tombstone. Active scheduled events retain the data required to operate the call.

The privacy/accounting retention schedule remains a draft. Automatic retention deletion must stay disabled until the legal/accounting reviewer approves periods and exceptions. Cancel provider events before deleting associated booking records. Backups must expire under the configured Railway schedule, and restored data must re-enter the approved deletion process.

## Staged rollout and rollback

The global `OPENWA_ENABLED` flag is the hard application stop. `OPENWA_ROLLOUT_STAGE` defaults to `off` and gates new event creation:

1. `internal_test`: allowlisted test customer emails only (`OPENWA_ROLLOUT_CUSTOMER_EMAILS`).
2. `staff_pilot`: bookings whose customer email belongs to an `is_staff` account only.
3. `limited_customer`: explicitly allowlisted customer emails only.
4. `general`: all otherwise eligible confirmed bookings.

Paid sessions remain disabled unless separately approved. At each stage verify readiness, event creation/cancellation, recipient deliveries, alerts, and fallback before expanding. Set `OPENWA_ROLLOUT_STAGE=off` to roll back new event creation while preserving existing bookings and links and allowing existing events to be reconciled/cancelled. Use `OPENWA_ENABLED=false` only as a hard stop because it also blocks cancellation/reconciliation operations.

## Test coverage and deferred live acceptance

Automated coverage verifies bearer-authenticated API access, pairing UI default-off/Basic-auth behavior, generic health endpoints, encrypted event persistence, idempotency, provider failure/reconciliation, cancellation data minimization, metrics, rollout-stage allowlists, rollback link preservation, staff alert/recovery behavior, and the assembled Laravel booking-to-dashboard call-link state. The traceable test matrix and manual device runbook are in [public booking journey acceptance](../testing/public-booking-journey-acceptance.md). Real WhatsApp account bootstrap, Android/iOS joining, restart/reconnect, re-authentication, backup restore, and emergency disconnect remain untested against a live device and are reserved for #220.

## Cost and approval

Current service resource usage is not a valid cost forecast because no authenticated WhatsApp session or production workload has run. Volume and incremental backup storage are billed by GB/minute. Confirm the Railway plan, backup storage, CPU/memory/egress usage, and monthly budget in the billing dashboard after #220's test window. Record the provider/hosting terms review and explicit product/operations GO decision before general enablement.
