# WhatsApp Business Platform: setup and operations

Easy English uses the Meta WhatsApp Business Platform Cloud API for transactional service messages only. It does **not** automate or place voice/video calls. The teacher and customer use the WhatsApp application for the 15-minute speaking session. Email remains a separate notification channel.

Production sending is disabled by default. Keep `WHATSAPP_ENABLED=false` until the Meta business assets, templates, secrets, webhook, consent flow, and live end-to-end tests have been completed. CI uses a fake gateway and does not require Meta credentials.

## 1. Meta assets and access

In Meta Business Manager / Business Portfolio and Meta for Developers:

1. Use the Easy English business portfolio, or create one owned by the business owner.
2. Create or select a Meta app and add the WhatsApp product.
3. Create/select a WhatsApp Business Account (WABA) and connect the dedicated business phone number. Complete any display-name, business verification, and number-registration requirements shown in the Meta dashboard.
4. Record the **Phone Number ID** and **WhatsApp Business Account ID**. These are identifiers, not credentials, but should still be stored only in deployment configuration.
5. For production, create a system user and issue a long-lived system-user access token with the minimum WhatsApp permissions required by Meta, including `whatsapp_business_messaging`. Do not use a short-lived setup token for production.
6. Keep the Meta **App Secret** and a separately generated, high-entropy webhook verification token in the deployment secret store. Never commit them or expose them in browser code, logs, or screenshots.
7. Confirm the currently supported Graph API version in Meta's dashboard/docs before deployment. The sample `.env.example` currently defaults to `v26.0`; override it if Meta has retired that version before launch.

## 2. Environment configuration

Configure these values on the Hostinger application environment, not in Git:

```dotenv
WHATSAPP_ENABLED=false
WHATSAPP_API_VERSION=v26.0
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_BUSINESS_ACCOUNT_ID=your_business_account_id
WHATSAPP_ACCESS_TOKEN=your_long_lived_system_user_token
WHATSAPP_APP_SECRET=your_meta_app_secret
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_random_webhook_verification_token
WHATSAPP_TEMPLATE_LANGUAGE_RO=ro
WHATSAPP_TEMPLATE_LANGUAGE_EN=en
```

Set each `WHATSAPP_TEMPLATE_*` variable to the exact approved template name in Meta. The contact-verification template is `easyenglish_activation`, with approved locale variants `ro` and `en`. Its authentication payload contains one `body` text parameter and a URL copy-code button parameter at index `0`; do not send it as a body-only template. The defaults for other transactional templates remain proposed names only, not templates that have been created or approved. Keep sending disabled while testing or while any required template is unavailable.

**Current verification-template expiry mismatch:** both approved `easyenglish_activation` variants currently display a 10-minute expiry and their copy-code URL declares `code_expiration_minutes=10`, while the application challenge remains valid for 30 minutes. Before enabling WhatsApp, edit both Authentication variants in WhatsApp Manager so the body/footer and copy-code expiry consistently state 30 minutes, then wait for approval and re-fetch their definitions. The template MCP cannot edit Authentication templates directly. Do not shorten or extend the application challenge as a workaround; its 30-minute expiry is part of the verification contract.

The Cloud API adapter sends only approved **template messages**. This deliberately avoids relying on the 24-hour customer-service window for transactional messages and avoids sending arbitrary free-form content. Requests use a bearer token, a bounded timeout, a template payload, and `biz_opaque_callback_data` containing only the internal delivery ID. Provider response IDs are stored for status correlation.

## 3. Approved transactional templates

Create and submit these templates in Meta's WhatsApp Manager. Use the **Utility** category where Meta's current rules allow it. Template category, names, language variants, and approval are controlled by Meta. The app does not assume a template is approved just because it is configured.

Each template uses a body component with positional text parameters in the following order. Create both Romanian (`ro`) and English (`en_US`) variants, or configure the exact language code supported by the approved template:

| Environment variable | Proposed template key | Body parameters in order |
|---|---|---|
| `WHATSAPP_TEMPLATE_PURCHASE_CONFIRMATION` | `easyenglish_purchase_confirmation` | Customer name, bundle name, amount paid (RON), expiry date/time, current bundle status |
| `WHATSAPP_TEMPLATE_INTRO_BOOKING_CONFIRMATION` | `easyenglish_intro_booking_confirmation` | Customer name, appointment date/time, time zone |
| `WHATSAPP_TEMPLATE_APPOINTMENT_CONFIRMATION` | `easyenglish_appointment_confirmation` | Customer name, appointment date/time, time zone |
| `WHATSAPP_TEMPLATE_APPOINTMENT_REMINDER_24H` | `easyenglish_appointment_reminder_24h` | Customer name, appointment date/time, time zone |
| `WHATSAPP_TEMPLATE_APPOINTMENT_REMINDER_1H` | `easyenglish_appointment_reminder_1h` | Customer name, appointment date/time, time zone |
| `WHATSAPP_TEMPLATE_BOOKING_CANCELLED` | `easyenglish_booking_cancelled` | Customer name, cancelled appointment date/time |
| `WHATSAPP_TEMPLATE_BOOKING_RESCHEDULED` | `easyenglish_booking_rescheduled` | Customer name, previous date/time, new date/time |
| `WHATSAPP_TEMPLATE_TEACHER_CANCELLATION` | `easyenglish_teacher_cancellation` | Customer name, cancelled appointment date/time, updated expiry or TBC |
| `WHATSAPP_TEMPLATE_BOOKING_NO_SHOW` | `easyenglish_booking_no_show` | Customer name, missed appointment date/time, session-credit or one-time-offer outcome |
| `WHATSAPP_TEMPLATE_REFUND_SUCCEEDED` | `easyenglish_refund_succeeded` | Customer name, refund amount (RON), reason |
| `WHATSAPP_TEMPLATE_REFUND_FAILED` | `easyenglish_refund_failed` | Customer name, refund amount (RON), reason |
| `WHATSAPP_TEMPLATE_BUNDLE_EXPIRY_14D` | `easyenglish_bundle_expiry_14d` | Customer name, bundle name, remaining sessions, expiry date/time |
| `WHATSAPP_TEMPLATE_BUNDLE_EXPIRY_3D` | `easyenglish_bundle_expiry_3d` | Customer name, bundle name, remaining sessions, expiry date/time |

Write each template in plain, helpful language and clearly state that it is a service notification, not marketing. Appointment templates should tell the customer to keep WhatsApp available for the teacher's call. Do not say that the API is placing the call. Have Meta approve every required language variant and test that its parameter count/order exactly matches this table.

## 4. Customer opt-in and opt-out

The booking and bundle checkout forms require a separate, unchecked WhatsApp service-message consent checkbox. Its wording identifies purchase, appointment, reminder, cancellation, and expiry messages, explicitly excludes marketing, and explains that replying `STOP` opts out. This consent is separate from acceptance of the privacy/cancellation policy and is not treated as marketing consent.

`whatsapp_contacts` stores the normalized phone number, consent timestamp/source, and opt-out timestamp/source. A WhatsApp delivery is created only when `WHATSAPP_ENABLED=true` and that phone has current service-message consent with no opt-out. If the customer replies with `STOP`, `STOPALL`, `UNSUBSCRIBE`, `CANCEL`, `END`, `QUIT`, `PARAR`, `OPRIRE`, `DEZABONARE`, or `NU MAI TRIMITE`, the webhook records the opt-out and skips pending/queued WhatsApp deliveries for that number. Email notifications remain unaffected. A customer may opt back in only by explicitly checking the service-message consent box again.

Do not import or message existing customer phone numbers without a documented lawful basis and the opt-in required by Meta. Do not use this integration for promotional offers, newsletters, or marketing campaigns.

## 5. Webhook setup and security

Configure the callback URL as:

`https://YOUR_PUBLIC_HOST/webhooks/whatsapp`

Use `WHATSAPP_WEBHOOK_VERIFY_TOKEN` as the Meta verification token and subscribe the app/WABA to the `messages` webhook field. The endpoint implements:

- `GET /webhooks/whatsapp`: verifies `hub.mode`, `hub.verify_token`, and echoes `hub.challenge` as plain text. PHP may normalize dotted query keys to underscores, so both representations are accepted.
- `POST /webhooks/whatsapp`: verifies `X-Hub-Signature-256` against the **raw request body** using HMAC-SHA256 and `WHATSAPP_APP_SECRET` before parsing or trusting the payload. Invalid/missing signatures are rejected.
- Delivery receipts: stores the provider status (`sent`, `delivered`, `read`, `failed`), provider message ID, and a redacted provider error code. Event keys deduplicate repeated webhook deliveries, and older status events cannot regress `read` state.
- Inbound opt-out messages: processes only the supported opt-out keywords and does not log message content or phone numbers.

The webhook does not log payload bodies, access tokens, customer phone numbers, or customer message content. Keep the endpoint HTTPS-only and rate-limited. Configure monitoring for repeated invalid signatures and provider failures using delivery IDs and exception/error codes only.

## 6. Durable delivery and operations

WhatsApp uses the same `notification_deliveries` outbox as email, with a distinct channel and idempotency key per logical event. Outbox rows are created only after the related booking/payment/refund state change commits. The minute scheduler (`notifications:dispatch`) queues due reminders and pending deliveries; the database queue worker sends the provider request. The admin view is **Operations → Notification delivery** and supports audited manual retries for failed records.

The scheduler creates reminders in the `Europe/Bucharest` timezone approximately 24 hours and 1 hour before a confirmed appointment, plus bundle expiry reminders approximately 14 and 3 days before expiry. Each reminder stores the appointment time snapshot used when it was queued. A worker re-checks current booking status, current start time, and consent before sending; cancelled or rescheduled bookings and opted-out contacts are skipped. A rescheduled booking gets a new reminder key for the new appointment time.

Provider statuses progress through accepted/sent/delivered/read or failed. Duplicate webhook events are ignored. Meta does not provide a general exactly-once guarantee for a message request that times out after provider acceptance; the stable outbox record and callback correlation prevent duplicate *logical events*, but an ambiguous network failure can still result in a duplicate provider message on retry. If the request result is ambiguous, inspect delivery/provider status before manually retrying. Do not create a replacement outbox row.

## 7. Local and production validation

1. Keep `WHATSAPP_ENABLED=false` during local development unless you are deliberately testing with a Meta test number.
2. Run `php artisan migrate` and `php artisan schedule:list`; confirm `notifications:dispatch` is scheduled.
3. Run focused tests with the fake gateway: `php artisan test --filter=WhatsAppNotificationTest`.
4. In Meta development mode, add the test recipient and send one approved template in each configured language. Confirm provider acceptance and a delivered webhook status.
5. Test a rejected/failed template, duplicate webhook, invalid signature, `STOP` opt-out, and retry behavior.
6. Confirm all required templates are approved and the system-user token/permissions are valid.
7. Only after those checks, set `WHATSAPP_ENABLED=true` in production and reload Laravel's configuration cache.
8. Send one real end-to-end purchase and appointment confirmation to an opted-in staff test number, verify status callbacks, then check reminders and opt-out before public launch.

## 8. Troubleshooting

- **No WhatsApp delivery row:** verify `WHATSAPP_ENABLED=true`, the contact's consent timestamp, absence of opt-out, and the business event's eligibility.
- **Pending row never queues:** confirm the config flag is enabled, `notifications:dispatch` is running, and the queue uses the same database/environment.
- **Failed delivery:** check the HTTP status and exception class in restricted application logs, then inspect `provider_error_code` and the Meta message diagnostics. Do not log request headers or body.
- **Template rejected or paused:** fix/re-submit the template in Meta, update its configured name if needed, and use the audited retry action after confirming the template is approved.
- **Webhook verification fails:** compare the verification token, public HTTPS callback URL, subscription, and the exact Meta app secret used for HMAC verification.
- **Signature rejected:** verify that the proxy preserves the raw request body and `X-Hub-Signature-256` header and that `WHATSAPP_APP_SECRET` is the app secret, not the access token.
- **No delivery status updates:** verify the WABA subscription includes `messages`, that the callback receives status events, and that the provider message ID matches the stored `provider_message_id`.
- **Customer says STOP but messages continue:** verify the inbound text event reached the webhook and the normalized phone matches `whatsapp_contacts.phone`; pending/queued deliveries should be skipped immediately.

### Suggested approved template copy

Use these as starting copy for Meta's template review. The `{{1}}` notation is the positional variable order in the table above; create Romanian and English language variants with the same variable count. Meta may require edits or a different Utility classification.

| Template | English body | Romanian body |
|---|---|---|
| Contact verification (`WHATSAPP_TEMPLATE_CONTACT_VERIFICATION`) | `Your Easy English verification code is {{1}}. It expires in 30 minutes. Do not share this code with anyone.` | `Codul tău de verificare Easy English este {{1}}. Expiră în 30 de minute. Nu comunica acest cod nimănui.` |
| Purchase confirmation | `Hi {{1}}, your payment of {{3}} for {{2}} is confirmed. Current bundle status: {{5}}. The bundle expires on {{4}}. Schedule any remaining 15-minute sessions before then. Your teacher calls through the WhatsApp app; this message does not place the call. Reply STOP to opt out of service messages.` | `Bună, {{1}}! Plata de {{3}} pentru {{2}} a fost confirmată. Starea actuală a pachetului: {{5}}. Pachetul expiră la {{4}}. Programează sesiunile de 15 minute rămase până atunci. Profesorul te sună din aplicația WhatsApp; acest mesaj nu inițiază apelul. Răspunde STOP pentru dezabonare.` |
| Intro booking confirmation | `Hi {{1}}, your free 15-minute introductory session is confirmed for {{2}} ({{3}}). Keep WhatsApp available: your teacher will call you in the WhatsApp app. This message does not place the call. Reply STOP to opt out.` | `Bună, {{1}}! Sesiunea introductivă gratuită de 15 minute este confirmată pentru {{2}} ({{3}}). Păstrează WhatsApp disponibil: profesorul te va suna din aplicație. Acest mesaj nu inițiază apelul. Răspunde STOP pentru dezabonare.` |
| Appointment confirmation | `Hi {{1}}, your 15-minute Easy English session is confirmed for {{2}} ({{3}}). Keep WhatsApp available for your teacher's call in the app. Reply STOP to opt out of service messages.` | `Bună, {{1}}! Sesiunea Easy English de 15 minute este confirmată pentru {{2}} ({{3}}). Păstrează WhatsApp disponibil pentru apelul profesorului din aplicație. Răspunde STOP pentru dezabonare.` |
| 24-hour reminder | `Hi {{1}}, reminder: your 15-minute Easy English session is scheduled for {{2}} ({{3}}), about 24 hours from now. Keep WhatsApp available for the teacher's call. Reply STOP to opt out.` | `Bună, {{1}}! Memento: sesiunea Easy English de 15 minute este programată pentru {{2}} ({{3}}), peste aproximativ 24 de ore. Păstrează WhatsApp disponibil pentru apelul profesorului. Răspunde STOP pentru dezabonare.` |
| 1-hour reminder | `Hi {{1}}, your 15-minute Easy English session starts in about one hour, at {{2}} ({{3}}). Please keep WhatsApp available for your teacher's call. Reply STOP to opt out.` | `Bună, {{1}}! Sesiunea Easy English de 15 minute începe peste aproximativ o oră, la {{2}} ({{3}}). Păstrează WhatsApp disponibil pentru apelul profesorului. Răspunde STOP pentru dezabonare.` |
| Booking cancelled | `Hi {{1}}, your Easy English session on {{2}} has been cancelled. Credit outcome: {{3}}. If you need help with your next booking, contact the Easy English team. Reply STOP to opt out.` | `Bună, {{1}}! Sesiunea Easy English din {{2}} a fost anulată. Situația creditului: {{3}}. Dacă ai nevoie de ajutor, contactează echipa Easy English. Răspunde STOP pentru dezabonare.` |
| Booking rescheduled | `Hi {{1}}, your Easy English session has changed from {{2}} to {{3}}. Please use the new time and keep WhatsApp available for your teacher's call. Reply STOP to opt out.` | `Bună, {{1}}! Sesiunea Easy English a fost mutată de la {{2}} la {{3}}. Folosește noua oră și păstrează WhatsApp disponibil pentru apelul profesorului. Răspunde STOP pentru dezabonare.` |
| Teacher cancellation | `Hi {{1}}, your teacher had to cancel the session on {{2}}. Your session credit will be restored under the applicable policy. Updated bundle expiry: {{3}}. The Easy English team will help with the next step. Reply STOP to opt out.` | `Bună, {{1}}! Profesorul a trebuit să anuleze sesiunea din {{2}}. Creditul va fi restituit conform politicii aplicabile. Noua dată de expirare a pachetului: {{3}}. Echipa Easy English te va ajuta cu următorul pas. Răspunde STOP pentru dezabonare.` |
| No-show | `Hi {{1}}, the Easy English session on {{2}} was recorded as missed by staff. The session credit was forfeited under the accepted policy. Contact the team if you believe this is incorrect. Reply STOP to opt out.` | `Bună, {{1}}! Echipa a înregistrat absența de la sesiunea din {{2}}. Situație: {{3}}. Contactează echipa dacă înregistrarea este incorectă. Răspunde STOP pentru dezabonare.` |
| Refund succeeded | `Hi {{1}}, your Easy English refund of {{2}} has been confirmed. Reason: {{3}}. Reply STOP to opt out of service messages.` | `Bună, {{1}}! Rambursarea Easy English de {{2}} a fost confirmată. Motiv: {{3}}. Răspunde STOP pentru dezabonare.` |
| Refund failed | `Hi {{1}}, your Easy English refund of {{2}} was not completed. Reason: {{3}}. Contact the Easy English team if you need help. Reply STOP to opt out.` | `Bună, {{1}}! Rambursarea Easy English de {{2}} nu a fost finalizată. Motiv: {{3}}. Contactează echipa Easy English dacă ai nevoie de ajutor. Răspunde STOP pentru dezabonare.` |
| Bundle expiry (14 days) | `Hi {{1}}, your {{2}} bundle has {{3}} sessions remaining and expires on {{4}}. Schedule eligible sessions before expiry. Reply STOP to opt out.` | `Bună, {{1}}! Pachetul {{2}} mai are {{3}} sesiuni și expiră la {{4}}. Programează sesiunile eligibile înainte de expirare. Răspunde STOP pentru dezabonare.` |
| Bundle expiry (3 days) | `Hi {{1}}, your {{2}} bundle has {{3}} sessions remaining and expires on {{4}}. It expires in about 3 days, so schedule any remaining sessions soon. Reply STOP to opt out.` | `Bună, {{1}}! Pachetul {{2}} mai are {{3}} sesiuni și expiră la {{4}}. Expiră în aproximativ 3 zile, așa că programează sesiunile rămase cât mai curând. Răspunde STOP pentru dezabonare.` |

The provider adapter always sends the named approved template with the configured language and positional body parameters. Do not append ad-hoc text outside the approved template. The templates must be reviewed and approved in Meta before enabling sends.
