# Transactional email and notification outbox

Easy English sends service emails through the existing SMTP account. Email is a downstream effect: booking, payment, refund, and credit state must be committed independently of SMTP delivery. A mail failure must never undo a verified payment or duplicate a booking.

## SMTP configuration

Configure these values in the deployment environment. Keep credentials out of source control and logs.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_SCHEME=tls
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_TIMEOUT=10
MAIL_FROM_ADDRESS=your-verified-sender@example.com
MAIL_FROM_NAME="Easy English"
QUEUE_CONNECTION=database
```

Use the host/port/encryption combination documented by the existing SMTP provider. Port 587 commonly uses STARTTLS; port 465 commonly uses implicit TLS. Do not guess if the provider specifies otherwise. Use a verified sender address on a domain you control. `MAIL_MAILER=log` is suitable for local development; CI uses Laravel's mail fake and does not require an SMTP account.

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

SMTP does not provide a universal exactly-once delivery guarantee: if the provider accepts a message and the connection drops before the application records success, a retry can deliver the same logical email again. The unique outbox record and worker lock prevent concurrent queue duplicates; a stable logical event key prevents repeated business events from generating new messages. Do not create a replacement outbox record to work around a timeout.

## Domain authentication and deliverability

Before launch, configure these records at the sender domain's DNS provider using values supplied by the SMTP provider:

- **SPF:** publish the provider's required SPF include/mechanism, ensuring there is only one SPF TXT record for the domain.
- **DKIM:** publish the provider's selector/public key and enable signing in the provider account.
- **DMARC:** start with a monitoring policy such as `p=none` and a monitored `rua` address, review alignment and reports, then strengthen policy as operationally appropriate.

Do not copy generic SPF/DKIM values from this runbook; DNS values are provider- and domain-specific. Verify SPF, DKIM, and DMARC alignment on a delivered message and test Gmail, Outlook, and a Romanian mailbox provider before launch.

## Local testing

1. Set `MAIL_MAILER=log` for manual development or use `Mail::fake()` in tests.
2. Run `php artisan migrate` on the local database.
3. Run `php artisan schedule:list` and confirm `notifications:dispatch` is scheduled.
4. Run `php artisan notifications:dispatch` to enqueue due outbox records.
5. Run `php artisan queue:work database --stop-when-empty --max-time=50 --max-jobs=100 --tries=3 --timeout=30` to process queued emails locally.
6. Inspect `notification_deliveries` and the `Notification delivery` admin view for status and redacted failure reason.

## Troubleshooting

- **No email was queued:** verify the event's state transition committed, the event is eligible, and a unique outbox record exists. Bundle purchase messages require an active entitlement and a succeeded payment.
- **Delivery remains pending:** check `next_attempt_at`, queue cron execution, and `jobs`/`failed_jobs` tables. A pending outbox row is safe to retry.
- **Delivery is queued but never sent:** verify the database queue worker actually runs and is using the same environment/database as the web app.
- **Delivery is failed:** inspect the exception class recorded in `last_error` and the restricted application logs, fix SMTP/DNS configuration, then use the audited retry action.
- **Message appears twice:** compare the event's idempotency key, delivery attempts, provider delivery logs, and timestamps. SMTP acceptance followed by a dropped connection can produce an at-least-once duplicate; do not create a new logical event to retry it.
- **Mail reaches spam:** verify sender alignment, SPF/DKIM/DMARC, provider reputation, message headers, and content; do not change sender identity without verifying the new domain.

Never log SMTP passwords, full message bodies, full recipient lists, payment payloads, or customer notes. Use the delivery ID and exception class to correlate operational failures.
