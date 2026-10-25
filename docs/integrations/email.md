# Transactional email and notification outbox

Easy English sends service emails through Laravel’s mail transport. Resend is the selected provider for the initial transactional-email setup; Laravel’s built-in Resend transport keeps the application independent of Resend-specific application code. Email is a downstream effect: booking, payment, refund, and credit state must be committed independently of provider delivery. A mail failure must never undo a verified payment or duplicate a booking.

## Resend configuration

Resend is the selected provider for transactional email. The application uses Laravel’s built-in `resend` mail transport and the `resend/resend-php` package; no custom Resend client or parallel delivery path is needed.

Configure these values in the deployment secret/environment manager for `ee.scl.ro`. Keep credentials out of source control, issue comments, shell history, and logs.

```dotenv
MAIL_MAILER=resend
RESEND_API_KEY=re_your_secret_key
MAIL_FROM_ADDRESS=verificare@easyenglish.ro
MAIL_FROM_NAME="Easy English"
QUEUE_CONNECTION=database
```

`MAIL_FROM_ADDRESS` is an example sender identity, not a claim that it is already verified. Use it only after adding `easyenglish.ro` (or another controlled sending domain) to Resend and verifying the domain. Create a restricted API key for this application where Resend's current key permissions allow it. Never expose the key to browser code.

### Provider and DNS setup

1. Create the Resend account and add the chosen sending domain in the Resend dashboard.
2. Publish the exact DNS records shown by Resend at the domain's DNS provider. Verify the domain in Resend before sending. Do not copy generic SPF/DKIM records from this document or overwrite existing MX/SPF records blindly.
3. Ensure there is only one SPF TXT record for the domain, and follow Resend's current instructions for DKIM and any return-path records.
4. Configure DMARC for the sending domain. If the domain does not already have a policy, start with a monitored policy such as `p=none` and a monitored `rua` address; review alignment and reports before strengthening it. Check the domain's existing mail setup first.
5. Add `RESEND_API_KEY`, `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` to the `ee.scl.ro` deployment environment. Do not add them to GitHub, `.env.example` with real values, or logs.
6. Reload/rebuild Laravel's cached configuration after updating deployment variables. Confirm the resolved mailer name and sender address without printing the API key.
7. Send a controlled test to a designated inbox. Verify receipt, spam placement, sender identity, and SPF/DKIM/DMARC results in the delivered message headers and Resend dashboard.

Keep local development on `MAIL_MAILER=log` unless you intentionally configure a real provider. `log` must never be used for contact verification because it exposes the single-use verification URL in application logs. The `array` transport silently discards the message, and failover chains containing either transport are rejected. Contact verification fails closed when a non-delivering mailer is selected outside tests; CI uses fakes and does not require live provider credentials. See [contact verification](verification.md).

### Alternative SMTP configuration

SMTP remains available if a future provider choice requires it. Configure `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, and `MAIL_PASSWORD` using that provider's documented settings. Do not guess the port/encryption combination.

## Messages and language

The selected Romanian/English locale is persisted with the booking or bundle at creation time, so a later browser-language change does not unexpectedly change an existing customer's email language. Transactional templates are in `resources/views/emails/transactional.blade.php` and `resources/views/emails/transactional-text.blade.php`, with translated subjects and content in `lang/ro/email.php` and `lang/en/email.php`.

The outbox supports:

- successful bundle purchase, only after verified Stripe payment;
- confirmed free introductory appointment and confirmed bundle appointment;
- appointment reminder approximately 24 hours before the appointment;
- cancellation, rescheduling, and teacher-cancellation outcomes;
- successful or failed refund outcome;
- bundle expiry reminders approximately 14 days and 3 days before expiry.

Bundle purchase confirmation explicitly says appointments are not scheduled automatically. Appointment messages state the local Europe/Bucharest time, 15-minute duration, and WhatsApp call instructions. Bundle-expiry reminders are generated only for active entitlements with remaining credits; refunded or voided bundles are not eligible. Messages are service communications, not marketing.

## Durable processing and retries

`notification_deliveries` is the transactional outbox. Event creation uses a unique idempotency key so replayed payment webhooks, repeated booking confirmation, or repeated scheduler runs do not create multiple logical notification records. Outbox creation happens in the same database transaction as the verified payment/booking/refund state change.

`php artisan notifications:dispatch` generates due reminders and queues eligible email records. The Laravel scheduler runs this command every minute. The database queue worker sends the email. Delivery statuses are `pending`, `queued`, `sending`, `sent`, and `failed`; attempt count, next retry time, sent time, and a redacted error are stored on each record.

Retry delays are 1 minute, 5 minutes, 15 minutes, 1 hour, and 4 hours. After five retries (six attempts total, including the initial attempt) the record becomes `failed`. A reminder that is no longer relevant because the booking changed or the bundle became ineligible is marked `skipped` rather than retried. A stale `sending` worker lease is returned to the retry queue after 20 minutes; queued jobs are not reset merely because the queue is delayed. The admin panel's **Operations → Notification delivery** view shows failures and provides a confirmed retry action with an audit record. The retry action reuses the same logical outbox record.

Email providers do not provide a universal exactly-once delivery guarantee: if the provider accepts a message and the connection drops before the application records success, a retry can deliver the same logical email again. The unique outbox record and worker lock prevent concurrent queue duplicates; a stable logical event key prevents repeated business events from generating new messages. Do not create a replacement outbox record to work around a timeout.

## Domain authentication and deliverability

Before launch, configure these records at the sender domain's DNS provider using values supplied by Resend:

- **SPF:** publish the provider's required SPF include/mechanism, ensuring there is only one SPF TXT record for the domain.
- **DKIM:** publish the provider's selector/public key and enable signing in the provider account.
- **DMARC:** start with a monitoring policy such as `p=none` and a monitored `rua` address, review alignment and reports, then strengthen policy as operationally appropriate.

Do not copy generic SPF/DKIM values from this runbook; DNS values are provider- and domain-specific. Verify SPF, DKIM, and DMARC alignment on a delivered message and test Gmail, Outlook, and a Romanian mailbox provider before launch.

## Local testing

1. Keep `MAIL_MAILER=log` for manual local development; use `Mail::fake()` in tests. Do not use the log transport for contact verification.
2. Run `php artisan migrate` on the local database.
3. Run `php artisan schedule:list` and confirm `notifications:dispatch` is scheduled.
4. Run `php artisan notifications:dispatch` to enqueue due outbox records.
5. Run `php artisan queue:work database --stop-when-empty --max-time=50 --max-jobs=100 --tries=3 --timeout=30` to process queued emails locally.
6. Inspect `notification_deliveries` and the `Notification delivery` admin view for status and redacted failure reason.

## Troubleshooting

- **No email was queued:** verify the event's state transition committed, the event is eligible, and a unique outbox record exists. Bundle purchase messages require an active entitlement and a succeeded payment.
- **Delivery remains pending:** check `next_attempt_at`, queue cron execution, and `jobs`/`failed_jobs` tables. A pending outbox row is safe to retry.
- **Delivery is queued but never sent:** verify the database queue worker actually runs and is using the same environment/database as the web app.
- **Delivery is failed:** inspect the exception class recorded in `last_error` and the restricted application logs, fix Resend credentials, sender verification, or DNS configuration, then use the audited retry action.
- **Message appears twice:** compare the event's idempotency key, delivery attempts, provider delivery logs, and timestamps. Provider acceptance followed by a dropped connection can produce an at-least-once duplicate; do not create a new logical event to retry it.
- **Mail reaches spam:** verify sender alignment, SPF/DKIM/DMARC, Resend domain status, provider reputation, message headers, and content; do not change sender identity without verifying the new domain.

Never log Resend API keys, SMTP passwords, full message bodies, full recipient lists, payment payloads, or customer notes. Use the delivery ID and exception class to correlate operational failures.
