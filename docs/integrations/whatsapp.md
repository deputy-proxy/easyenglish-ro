# WhatsApp integration: purpose-based routing

## Routing policy

| Purpose | Provider | Control |
|---|---|---|
| Contact-verification OTPs and their delivery-status callbacks | Meta Cloud API only | META_WHATSAPP_VERIFICATION_ENABLED |
| Customer and teacher transactional notifications (excluding call-link-ready messages) | OpenWA only | OPENWA_MESSAGING_ENABLED |
| Customer and teacher scheduled call-link notifications | Meta Cloud API approved templates | META_WHATSAPP_TRANSACTIONAL_ENABLED |
| Scheduled WhatsApp call-event creation, retrieval, cancellation, and reconciliation | OpenWA call-event service | OPENWA_ENABLED |
| Transactional email | Laravel mail transport | MAIL_* |

Routing is fixed in code through purpose-specific gateway contracts. There is no global provider selector and no automatic cross-provider fallback. Meta outages do not affect OpenWA notifications, and OpenWA outages do not redirect OTPs through Meta. Email remains independent.

Laravel remains the source of truth for verification challenges, notification outbox rows, consent/opt-out state, delivery status, retries, and audit history. The scheduled call-event integration remains separate from messaging.

## Meta: contact-verification OTPs only

Meta sends the approved easyenglish_activation authentication template and, when explicitly enabled, the approved customer/teacher call-link templates. Configure these values in Hostinger secrets:

~~~dotenv
META_WHATSAPP_VERIFICATION_ENABLED=true
META_WHATSAPP_TRANSACTIONAL_ENABLED=false
WHATSAPP_API_VERSION=v26.0
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_BUSINESS_ACCOUNT_ID=...
WHATSAPP_ACCESS_TOKEN=...
WHATSAPP_APP_SECRET=...
WHATSAPP_WEBHOOK_VERIFY_TOKEN=...
WHATSAPP_TEMPLATE_CONTACT_VERIFICATION=easyenglish_activation
WHATSAPP_TEMPLATE_CONTACT_VERIFICATION_LANGUAGE_RO=ro
WHATSAPP_TEMPLATE_CONTACT_VERIFICATION_LANGUAGE_EN=en
~~~

META_WHATSAPP_VERIFICATION_ENABLED is the explicit switch. For backward compatibility only, Laravel falls back to legacy WHATSAPP_ENABLED when the new variable is absent. Migrate the deployment to the new name. WHATSAPP_PROVIDER is obsolete and has no routing effect.

Use a long-lived system-user token with minimum required WhatsApp permissions. Store the App Secret and webhook verification token as secrets. Never commit credentials or expose them in browser code or logs.

The easyenglish_activation authentication template must be approved in Romanian (ro) and English (en). It uses one body text parameter and a URL copy-code button parameter at index 0.

**Template expiry prerequisite:** existing approved variants have been documented as showing a 10-minute expiry, while Laravel challenges remain valid for 30 minutes. Before enabling Meta verification in production, align the approved template body/footer and copy-code expiry with the 30-minute challenge and wait for approval. Do not change the Laravel challenge lifetime as a workaround.

ContactVerificationService uses WhatsAppVerificationGateway and requires complete Meta configuration before sending. If Meta is disabled or incomplete, the existing verification error path is used; OpenWA is never attempted. Email-only verification remains available when verification mode is email_only.

Each challenge stores its Meta provider message ID and status (accepted, sent, delivered, read, failed, or ambiguous). Meta opaque callback data carries the challenge identifier so status callbacks can update the correct challenge, including when the initial acknowledgement was ambiguous. Challenge expiry, code attempt limits, resend cooldown, and email verification behavior remain unchanged.

## Meta: scheduled call-link notifications

When `META_WHATSAPP_TRANSACTIONAL_ENABLED=true`, Laravel sends only `customer_call_link_ready` and `teacher_call_link_ready` through Meta Cloud API. Configure and obtain approval for the `WHATSAPP_TEMPLATE_CUSTOMER_CALL_LINK_READY` and `WHATSAPP_TEMPLATE_TEACHER_CALL_LINK_READY` templates in the same Meta WhatsApp Business account/phone number configured by `WHATSAPP_PHONE_NUMBER_ID` and `WHATSAPP_ACCESS_TOKEN`. Both Romanian and English variants must be approved for the languages used by the booking and teacher profiles. The call URL is passed as a body text parameter. These are template messages, so they can be delivered outside the 24-hour customer-service window.

The Meta transactional flag is independent from `META_WHATSAPP_VERIFICATION_ENABLED`. Call-link notifications are never sent through OpenWA as a fallback. If Meta transactional delivery is disabled or misconfigured, the existing outbox delivery remains retryable and visible to staff. All other customer and teacher transactional notifications continue through OpenWA.

## OpenWA: transactional notifications

OpenWA sends ordinary WhatsApp notifications to customers and teachers: booking confirmations, teacher notifications, reminders, cancellations, bundle expiry, and refund notices.

Configure matching settings on Hostinger and Railway:

~~~dotenv
# Hostinger Laravel
OPENWA_MESSAGING_ENABLED=true
OPENWA_BASE_URL=https://<existing-railway-openwa-domain>
OPENWA_SERVICE_TOKEN=...
OPENWA_MESSAGING_TIMEOUT=20
OPENWA_MESSAGING_CALLBACK_SECRET=...

# Railway OpenWA service
OPENWA_MESSAGING_ENABLED=true
OPENWA_ALLOW_LIVE_TEST=true
OPENWA_MESSAGING_CALLBACK_URL=https://<easyenglish-host>/webhooks/openwa
OPENWA_MESSAGING_CALLBACK_SECRET=<same-secret-as-hostinger>
OPENWA_LICENSE_KEY=... # optional Restricted/Premium licence for new conversations
~~~

Keep the existing single-replica deployment, persistent /data volume, service token, session encryption key, and callback configuration. The authenticated /v1/capabilities endpoint reports messaging readiness, not unrestricted recipient reachability.

The OpenWA adapter is bound only to WhatsAppNotificationGateway. NotificationOutboxService, DispatchNotificationOutbox, and SendNotificationDelivery use OpenWA exclusively. Each WhatsApp outbox row is attributed to openwa and its provider attempt is persisted before the external request. A missing or unavailable OpenWA configuration never redirects a notification through Meta.

OpenWA renders the notification catalogue into text. Meta transactional templates for purchases, bookings, reminders, refunds, and bundle expiry are not required for ordinary notifications.

## First-time-recipient licensing acceptance test

This must be verified against the deployed OpenWA service and its current account/licence arrangement. CI cannot prove it.

1. Confirm the service is authenticated and /v1/capabilities reports messagingReady: true.
2. Use an opted-in test number that has never previously been contacted by this OpenWA account.
3. Trigger a real transactional notification through Laravel outbox.
4. Record the delivery ID, provider attempt, service response, and recipient outcome.
5. If the service rejects the recipient with LICENSE_REQUIRED, obtain the required Restricted/Premium licence and configure it on the Railway OpenWA service using OPENWA_LICENSE_KEY. This key is optional and must never be stored in Laravel.
6. Repeat the test and verify a successful send.
7. Do not enable general customer traffic until this test passes. Do not add automatic Meta fallback to work around a restriction.

This is a production acceptance gate, not a reason to block implementation or automated CI.

## Consent, opt-out, and inbound callbacks

The booking and bundle checkout forms require a separate, unchecked WhatsApp service-message consent checkbox. It excludes marketing and explains that replying STOP opts out. Consent is separate from acceptance of the privacy/cancellation policy.

whatsapp_contacts stores normalized phone number, consent timestamp/source, and opt-out timestamp/source. A WhatsApp delivery is created only when OpenWA messaging is configured and the recipient has current service-message consent with no opt-out.

Both authenticated inbound callbacks feed the same normalized message/opt-out handler:
- Meta: POST /webhooks/whatsapp, authenticated with X-Hub-Signature-256 over the raw request body and WHATSAPP_APP_SECRET.
- OpenWA: POST /webhooks/openwa, authenticated with HMAC-SHA256 in X-OpenWA-Signature and the shared callback secret.

STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT, PARAR, OPRIRE, DEZABONARE, and NU MAI TRIMITE trigger opt-out. Opt-out skips pending/queued WhatsApp deliveries for both customer and teacher recipients. Email remains unaffected. Re-consent requires a fresh explicit service-message consent action.

Meta status callbacks update only verification challenges correlated by opaque callback data/stored Meta message ID or legacy notification rows whose recorded provider is meta. They cannot update OpenWA notification deliveries. OpenWA inbound messages can trigger shared opt-out handling but do not update Meta verification status. Provider attempts are matched by provider/message ID.

## Outbox and operations

WhatsApp uses notification_deliveries with a distinct channel and idempotency key per logical event. Rows are created after the related business-state change commits. notifications:dispatch queues due reminders and pending deliveries. Workers re-check booking status, current time, recipient eligibility, and consent before sending.

If an OpenWA request times out after possible acceptance, the delivery is marked ambiguous and excluded from automatic retries. Reconcile the original OpenWA idempotency key in Admin → Operations → Notification delivery before any manual retry. Never create a replacement outbox row or send an ambiguous notification through Meta.

Email remains an independent channel. A WhatsApp failure must not suppress email or roll back a booking/payment/refund.

## Separate scheduled call events

OPENWA_ENABLED controls scheduled call-event creation, retrieval, cancellation, and reconciliation. OPENWA_MESSAGING_ENABLED controls ordinary WhatsApp notifications. Do not conflate these flags or disable scheduled call events as part of a messaging configuration change.

## Status and deployment

Run:

~~~sh
php artisan whatsapp:provider-status
php artisan openwa:monitor
~~~

The status command reports Meta verification, OpenWA notification readiness, and scheduled call-event readiness independently. It does not send a message.

This code change does not modify production environment variables. Before deployment, coordinate a migration to explicit META_WHATSAPP_VERIFICATION_ENABLED, keep OPENWA_MESSAGING_ENABLED aligned on Hostinger and Railway, leave OPENWA_ENABLED unchanged, and remove obsolete WHATSAPP_PROVIDER. Then run config:cache, queue:restart, both status commands, the Meta 30-minute template approval prerequisite, and the OpenWA first-time-recipient licensing test before enabling general customer traffic.
