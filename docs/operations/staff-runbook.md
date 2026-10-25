# Staff operating runbook

This guide covers routine booking and payment operations in Filament. Use a provisioned staff account and follow the documented authorization and audit rules. Do not edit database rows directly to force booking, credit, payment, or refund state.

## Access and privacy

- Sign in at `/admin` with your individually provisioned account. If access is denied, contact the designated administrator; do not share credentials or create a public account.
- Open only learner records needed for the task. Session goals and notes are personal data; do not copy them into external tickets or messages unless necessary and approved.
- Never request or record payment card details, passwords, provider tokens, or other sensitive information in booking notes.
- Staff changes to availability, booking state, credit balance, cancellations, no-shows, and refunds must use the relevant Filament actions and provide a reason when requested.

## Manage availability

1. Open the availability rules and review the recurring weekly windows.
2. Add or update a weekly window only after checking that it does not overlap another active rule.
3. Use a blocked-period exception for time off or a one-off unavailable period; use an override only when the teacher is intentionally available outside the normal schedule.
4. Confirm the public weekly grid reflects the intended date and Europe/Bucharest time.
5. Remember that every session lasts 15 minutes and the system requires a 15-minute buffer between sessions. The server rechecks availability when a booking is submitted.

## Find and prepare for a booking

1. Open **Bookings** and search by public reference, customer contact, or appointment time.
2. Verify the booking status, local start time, learner level, session goal/notes, policy version, entitlement/credit type, and notification status.
3. Use only the information needed to conduct the session. The session call is made by the teacher in the WhatsApp application, not by the WhatsApp messaging API.
4. If a booking is pending or a payment/hold looks stale, inspect the current state and reconciliation records before taking action. Do not assume a browser success page or sent notification proves payment.

## Cancellation and rescheduling

Apply the policy version accepted by the learner and the current approved customer terms. Mandatory law or an approved exception may provide greater rights.

- At least 24 hours before the scheduled start: cancellation restores the credit.
- From 12 hours to less than 24 hours: one free reschedule is permitted; otherwise the credit is forfeited.
- Less than 12 hours: the credit is forfeited under the standard policy.
- No-show: record only after the scheduled session and use the authorized no-show action; the credit/free offer is forfeited under the standard policy.
- Teacher cancellation: use the teacher-cancellation action, restore the credit, and allow the system's validity extension behavior where necessary.
- Rescheduling moves the existing booking and must not consume a second credit. Verify the old slot is released and the new slot respects availability/buffer constraints.

Never invent a reason or change a policy deadline to make an action succeed. If the facts are unusual or a legal exception applies, escalate to the authorized owner and record the reason through the approved audited exception process.

## Bundle purchases, credits, and expiry

- A bundle is active only after verified Stripe payment/reconciliation.
- Approved bundles: 156 RON (1 free + 4 paid), 390 RON (1 free + 10 paid), and 780 RON (1 free + 20 paid), each session 15 minutes and prices tax-inclusive.
- Bundle validity is three calendar months from successful payment in Europe/Bucharest. Appointment start must be before expiry, even if the booking request itself was made earlier.
- The free introduction is a separate no-purchase booking. The one-free-session allowance is shared across the standalone intro and included bundle credit and requires verified email and phone.
- Credit reservations/restorations/consumption/expiry are represented by an auditable ledger. Never manually adjust a balance to compensate for an uncertain payment/refund.

## Payments and refunds

1. Open the bundle entitlement and associated payment record.
2. For a pending payment, use the reconciliation workflow or escalate; do not mark it paid because the customer shows a success URL or screenshot.
3. Before a refund, verify the payment, unused paid credits, future appointments, existing pending refund attempts, and any affected reservations. Use the authorized refund action and record the reason.
4. If the provider status is ambiguous, leave affected credits/appointments protected and reconcile again. Do not initiate a second refund or tell the customer the refund succeeded until authoritative state confirms it.
5. On confirmed refund failure, verify the recovery action restored any reserved credits and the audit event is present.
6. Follow the accountant-approved invoice/receipt and correction-document procedure. An appointment consuming a previously purchased credit is not a new bundle sale.

## Email and WhatsApp delivery failures

1. Open **Operations → Notification delivery** and locate the record by delivery ID, event, channel, or booking reference.
2. Inspect status, attempts, next retry time, provider error code, and redacted error details. Do not expose full payloads or secrets.
3. Confirm the underlying booking/payment/refund state is correct before retrying the notification.
4. Fix the underlying configuration/provider/template/consent issue first. Use the audited retry action on the existing outbox record; do not create a replacement notification record.
5. For WhatsApp, verify the contact has current service-message consent and no opt-out. An opt-out must not be bypassed; email is unaffected.
6. If the provider may have accepted a request before a timeout, inspect provider status before retrying. Delivery is at-least-once, not guaranteed exactly-once.

## Escalation and incident recovery

Escalate immediately to the designated technical/owner contact when payment state is unclear, a refund is ambiguous, two bookings appear to overlap, credits are negative or inconsistent, the scheduler/queue has stopped, a webhook signature fails unexpectedly, or secrets/personal data may have been exposed.

Capture the time, booking/payment/refund/delivery reference, exact observed status, and redacted error class/code. Do not copy credentials, full webhook bodies, card details, customer notes, or full phone/email into the incident record. Preserve relevant payment/provider IDs for reconciliation.

For queue failures, inspect `php artisan queue:failed` and the Hostinger cron history, correct the root cause, then retry only the affected job when safe. For a stuck payment/refund, reconcile against Stripe before making changes. For deployment issues, follow the Hostinger rollback procedure and remember that restoring code does not automatically roll back database migrations.

## Operational commands

Run only when authorized and from the deployed application root with the correct environment:

```sh
php artisan schedule:list
php artisan notifications:dispatch
php artisan stripe:reconcile
php artisan queue:failed
```

Do not run destructive migration commands on production. Take and verify a backup before any reviewed migration or recovery operation.

## Launch-specific restrictions

- Production Stripe checkout stays disabled until live/test-mode webhook verification and launch approval are complete.
- Production WhatsApp sending stays disabled until Meta setup, approved templates, consent/opt-out checks, and live delivery verification pass.
- Automatic retention writes stay disabled until the privacy/legal retention schedule is approved; see [the retention policy](../legal/retention-policy.md).
- Romanian tax/invoicing and privacy documents remain launch blockers until reviewer sign-off is recorded in [the launch checklist](launch-checklist.md).
