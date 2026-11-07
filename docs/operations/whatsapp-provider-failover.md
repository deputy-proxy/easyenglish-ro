# WhatsApp provider failover runbook

## Purpose

Easy English can send transactional WhatsApp messages through either Meta WhatsApp Cloud API or the isolated OpenWA service. The active outbound provider is selected globally with `WHATSAPP_PROVIDER`. This runbook is for an operator responding to an OpenWA restriction, session logout, or provider outage.

The switch changes the provider used for **new submissions**. It does not migrate messages already accepted by a provider, and it does not automatically replay messages with an uncertain send outcome.

## Provider configuration

Configure both providers before relying on this as a continuity mechanism. Store secrets in Hostinger Variables and Railway Variables, never in Git.

### Meta on Hostinger

```dotenv
WHATSAPP_PROVIDER=meta
WHATSAPP_ENABLED=true
WHATSAPP_ALLOW_FREE_FORM=false
WHATSAPP_API_VERSION=v26.0
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_BUSINESS_ACCOUNT_ID=...
WHATSAPP_ACCESS_TOKEN=...
WHATSAPP_APP_SECRET=...
WHATSAPP_WEBHOOK_VERIFY_TOKEN=...
```

The Meta WABA, sender, long-lived system-user token, webhook, opt-out processing, and all transactional templates/language variants must remain active and approved. Keep `WHATSAPP_ALLOW_FREE_FORM=false` unless a caller explicitly establishes a valid customer-service window; normal notifications must use approved templates. `WHATSAPP_ENABLED` controls the Meta adapter; it is not the global provider selector.

### OpenWA on Hostinger

```dotenv
OPENWA_MESSAGING_ENABLED=true
OPENWA_BASE_URL=https://<existing-railway-openwa-domain>
OPENWA_SERVICE_TOKEN=...
OPENWA_MESSAGING_TIMEOUT=20
OPENWA_MESSAGING_CALLBACK_SECRET=...
OPENWA_MESSAGING_CALLBACK_URL=https://<easyenglish-host>/webhooks/openwa
```

### OpenWA on Railway

Configure the matching service variables on the existing `openwa-poc` service:

```dotenv
OPENWA_MESSAGING_ENABLED=true
OPENWA_ALLOW_LIVE_TEST=true
OPENWA_MESSAGING_CALLBACK_URL=https://<easyenglish-host>/webhooks/openwa
OPENWA_MESSAGING_CALLBACK_SECRET=<same-secret-as-hostinger>
```

Set `OPENWA_ALLOW_LIVE_TEST=true` only after the dedicated account and live messaging acceptance gate has been explicitly approved. Keep the existing `OPENWA_SERVICE_TOKEN`, persistent volume, session encryption key, one-replica deployment, and existing call-event configuration. `OPENWA_ENABLED` remains the independent feature flag for scheduled call events; do not repurpose it. Messaging is disabled by default on both sides.

The OpenWA service must be authenticated and `/v1/capabilities` must report `messagingReady: true`. Its callback must reach Hostinger over HTTPS. Do not use Railway private DNS from Hostinger.

## Pre-incident readiness checks

Run these before an incident, then repeat after changing provider configuration:

```sh
php artisan whatsapp:provider-status
```

The command prints the selected provider and secret-free configuration readiness. It does not send a message or prove remote account readiness.

For OpenWA, check the Railway deployment and session readiness:

```sh
curl -fsS https://<openwa-domain>/health/ready
curl -fsS -H 'Authorization: Bearer <OPENWA_SERVICE_TOKEN>' https://<openwa-domain>/v1/capabilities
```

Do not paste tokens into shared terminals, tickets, or logs. For Meta, verify the sender, access token, webhook subscription, and template approvals in Meta Business Manager. Perform a controlled test to an opted-in staff number through each provider at least once before relying on it.

**Phone-number compatibility gate:** Meta Cloud API and OpenWA may not be able to use the same WhatsApp number/session concurrently. Confirm the selected Meta sender and OpenWA account arrangement with the actual Meta/WhatsApp account configuration before pairing or enabling either provider. If the fallback uses a different number, make sure staff and customers know the sender may change after failover. Do not assume a shared number works merely because both sets of credentials exist.

## Emergency switch: OpenWA to Meta

1. In Hostinger, change only the selector:

   ```dotenv
   WHATSAPP_PROVIDER=meta
   ```

2. Confirm the Meta settings above are present and `WHATSAPP_ENABLED=true`.
3. Rebuild cached configuration and restart long-running workers:

   ```sh
   php artisan config:cache
   php artisan queue:restart
   ```

   If the hosting process manager does not automatically restart workers after `queue:restart`, restart the configured queue worker process using the Hostinger process manager.
4. Verify the selected provider:

   ```sh
   php artisan whatsapp:provider-status
   ```

   It must report `Active outbound provider: meta` and selected provider configuration `ready`.
5. Send one controlled notification to an opted-in staff test number and verify the Meta provider message ID/status in Admin → Operations → Notification delivery.
6. Keep OpenWA running for diagnosis, but do not switch back until its session is authenticated and the recovery checks below pass.

No code deployment or database migration is required for this switch. Existing queue workers must load the refreshed configuration, which is why the restart is mandatory.

## Handling queued and in-flight deliveries

- **Pending / queued, not yet submitted:** will use the newly selected provider when the worker first submits the message.
- **Sent / accepted / delivered / read:** remain attached to the provider that accepted the message and are never resent because the selector changed.
- **Failed with a definitive provider rejection:** may be retried through the selected provider using the same logical outbox record/idempotency key after checking the failure reason.
- **Ambiguous:** do not retry or create a replacement delivery. For an OpenWA delivery, use Admin → Operations → Notification delivery → Reconcile OpenWA. If OpenWA confirms `accepted`, the delivery is marked sent. If it confirms `failed` or has no submission record, it becomes eligible for a controlled retry. If it remains `sending` or `submission_unconfirmed`, leave it quarantined and investigate. Meta ambiguous sends require checking Meta's delivery/status diagnostics; do not blindly replay them.

A provider's `accepted` response means it accepted the submission, not that the recipient read it. The application cannot promise exactly-once delivery when a provider accepts a request and the connection fails before the acknowledgement is persisted.

## Failback: Meta to OpenWA

Switch back only when all of the following are true:

- The dedicated OpenWA session is authenticated and stable, with `/health/ready` returning `200`.
- `/v1/capabilities` reports `messagingReady: true`.
- The OpenWA callback URL and shared callback secret are correct, and inbound STOP messages are reaching the Laravel webhook.
- A controlled staff test message succeeds through OpenWA.
- The phone-number compatibility gate above has been satisfied.
- No unresolved ambiguous deliveries are being replayed.

Then change `WHATSAPP_PROVIDER=openwa` in Hostinger, run `php artisan config:cache` and `php artisan queue:restart`, and verify with `php artisan whatsapp:provider-status`. Send a controlled test and inspect its provider/attempt history.

## Rollback and escalation

If the selected provider is not ready after the switch, restore the last known-good selector and repeat the config-cache/worker-restart steps. Keep email notifications available. Do not disable the existing OpenWA call-event feature flag as part of a messaging-provider switch; scheduled call events are a separate integration and may still be needed for active bookings.

Escalate persistent ambiguous sends to an operator with access to the provider's message diagnostics. Never resolve uncertainty by creating a second notification row or changing the idempotency key.
