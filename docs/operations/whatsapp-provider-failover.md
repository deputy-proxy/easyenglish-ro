# WhatsApp purpose-based routing and operations runbook

## Routing policy

- Meta Cloud API: phone/contact verification OTPs and their delivery-status callbacks only.
- OpenWA: customer and teacher transactional notifications, inbound STOP handling, and ordinary WhatsApp messaging.
- OpenWA scheduled call events: separately controlled by OPENWA_ENABLED.
- Email: independent of both WhatsApp providers.

There is no global WHATSAPP_PROVIDER selector and no automatic cross-provider fallback. This runbook replaces the former provider-failover procedure. Do not use a provider switch to move notification traffic between Meta and OpenWA.

## Configuration

### Meta verification on Hostinger

~~~dotenv
META_WHATSAPP_VERIFICATION_ENABLED=true
WHATSAPP_API_VERSION=v26.0
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_BUSINESS_ACCOUNT_ID=...
WHATSAPP_ACCESS_TOKEN=...
WHATSAPP_APP_SECRET=...
WHATSAPP_WEBHOOK_VERIFY_TOKEN=...
WHATSAPP_TEMPLATE_CONTACT_VERIFICATION=easyenglish_activation
~~~

The approved easyenglish_activation Romanian and English variants must be aligned to the application's 30-minute challenge lifetime before enabling OTP delivery. WHATSAPP_ENABLED is a temporary backward-compatible fallback only when META_WHATSAPP_VERIFICATION_ENABLED is absent. Meta transactional templates are not used for ordinary notifications.

### OpenWA notifications on Hostinger

~~~dotenv
OPENWA_MESSAGING_ENABLED=true
OPENWA_BASE_URL=https://<existing-railway-openwa-domain>
OPENWA_SERVICE_TOKEN=...
OPENWA_MESSAGING_TIMEOUT=20
OPENWA_MESSAGING_CALLBACK_SECRET=...
~~~

### OpenWA service on Railway

~~~dotenv
OPENWA_MESSAGING_ENABLED=true
OPENWA_ALLOW_LIVE_TEST=true
OPENWA_MESSAGING_CALLBACK_URL=https://<easyenglish-host>/webhooks/openwa
OPENWA_MESSAGING_CALLBACK_SECRET=<same-secret-as-hostinger>
OPENWA_LICENSE_KEY=... # optional Restricted/Premium licence for new conversations
~~~

Keep the existing one-replica deployment, persistent /data volume, unchanged session encryption key, service token, and paired account. OPENWA_ENABLED remains the separate scheduled-call-event feature flag. Do not change it during messaging operations.

## Readiness checks

Run:

~~~sh
php artisan whatsapp:provider-status
php artisan openwa:monitor
~~~

The status command reports Meta verification configuration, OpenWA notification configuration and remote /v1/capabilities readiness, and scheduled call-event service readiness independently.

For OpenWA, confirm /health/ready returns HTTP 200, authenticated /v1/capabilities reports messagingReady: true, the paired session is authenticated, and callback URL/secret match the Laravel deployment. Readiness does not prove unrestricted recipient reachability. CI does not prove real WhatsApp delivery.

## First-time-recipient licensing gate

Before enabling general transactional messaging, send one controlled notification through Laravel outbox to an opted-in test number never previously contacted by this OpenWA account. Record the delivery ID, provider attempt, service response, and recipient outcome.

If OpenWA rejects the new recipient with LICENSE_REQUIRED, obtain the Restricted/Premium licence and configure it on the Railway service using OPENWA_LICENSE_KEY. This optional secret belongs only on the OpenWA service, never in Laravel. Repeat the test and manually retry the failed outbox row after configuration. This remains a rollout gate; it is not a reason to block implementation or add Meta fallback.

## Provider-specific failure handling

### Meta verification unavailable

- Do not route OTPs through OpenWA.
- Check META_WHATSAPP_VERIFICATION_ENABLED, sender credentials, App Secret, webhook verification token, approved authentication template, and template expiry.
- Keep email-only verification behavior unchanged if that mode is intentionally configured.
- Resolve the Meta issue before re-enabling WhatsApp phone verification.

### OpenWA notifications unavailable

- Do not route transactional notifications through Meta.
- Keep email notifications operating independently.
- Check the Railway service, authenticated session, OPENWA_MESSAGING_ENABLED, callback secret, and recipient eligibility.
- Recover OpenWA messaging and retain pending deliveries until the service is ready.
- For ambiguous delivery, reconcile the original OpenWA idempotency key before retrying. Never create a replacement row or blindly replay through another provider.

### Scheduled call events unavailable

Treat this as a separate OpenWA call-event incident. Preserve OPENWA_ENABLED and the existing event lifecycle/reconciliation procedure unless a separate call-event rollout change is approved. Do not change OPENWA_MESSAGING_ENABLED as a substitute.

## Delivery and callback rules

- New WhatsApp notification rows are attributed to openwa before submission.
- Meta status callbacks update only Meta-origin notification rows or correlated verification challenges.
- OpenWA inbound STOP messages and Meta inbound STOP messages use the same contact/opt-out store.
- Opt-out suppresses pending/queued notifications to both customer and teacher recipients; email is unaffected.
- Accepted/sent/delivered/read records are not resent because another capability is unavailable.
- Ambiguous OpenWA sends must be reconciled before manual retry.

## Deployment migration

The code deployment does not automatically change production environment variables. Coordinate the following after deployment:

1. Set META_WHATSAPP_VERIFICATION_ENABLED explicitly to the intended Meta OTP state.
2. Keep OPENWA_MESSAGING_ENABLED aligned on Hostinger and Railway.
3. Leave OPENWA_ENABLED unchanged unless a separate scheduled-call decision is approved.
4. Remove obsolete WHATSAPP_PROVIDER from deployment configuration; it no longer controls routing.
5. Run php artisan config:cache and php artisan queue:restart.
6. Run both readiness commands above.
7. Complete the Meta authentication-template approval and OpenWA first-time-recipient licensing tests before enabling general traffic.
