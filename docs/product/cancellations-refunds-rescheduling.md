# Cancellation, rescheduling, and refunds

## Status and launch gate

The rules below are implemented as server-side policy and audited state transitions. The published wording, statutory withdrawal rights, prepaid-service treatment, refund formula, and invoicing must still be reviewed and signed off by a qualified Romanian legal/accounting reviewer before launch. This document records the review as **pending**; it does not claim that a software rule overrides Romanian or EU consumer law. Where mandatory law gives the customer a greater right, that right takes precedence and staff must use the approved exception workflow with a reason and audit event.

## Customer-facing rules

All deadlines are measured against the appointment's scheduled start instant. Comparisons use timezone-aware timestamps; customer-facing times are displayed in Europe/Bucharest. The boundaries do not overlap.

| Time before appointment | Customer action | Credit outcome |
|---|---|---|
| 24 hours or more | Cancel | Restore the reserved bundle credit. This is not a cash refund. |
| 12 hours or more but less than 24 hours | One free reschedule, or cancel | A reschedule keeps the same credit attached to the booking. Cancelling without rescheduling forfeits the reserved credit. |
| Less than 12 hours | Cancel | Forfeit the reserved credit; no free reschedule. |
| Appointment has started / customer no-show | Staff may record a no-show with a reason | Forfeit the reserved credit. No-show is never inferred automatically. |
| Teacher cancellation | Teacher/staff cancels with a reason | Restore the reserved credit regardless of the deadline. If bundle expiry is too close, extend it so the restored credit has at least 14 days of validity remaining. |

At exactly 24 hours, the first row applies. At exactly 12 hours, the second row applies. The application uses absolute timestamps, not local wall-clock arithmetic, so daylight-saving changes do not move these boundaries.

A reschedule reserves the replacement slot inside the same database transaction before updating the original booking. The replacement must be available, must be in the future, and must start before the active bundle's expiry timestamp. It keeps the original booking ID and credit reservation; no additional credit is consumed. A customer receives one free reschedule under the 12-to-24-hour rule. Staff may override scheduling constraints only with an explicit reason and an audit event.

For a bundle appointment cancelled at least 24 hours before start, only the reserved session credit is restored. Cash refunds are a separate staff-reviewed process. A free introductory appointment has no paid bundle credit. Customer cancellation does not create a second free offer; a teacher cancellation makes the existing free-session claim available for one replacement booking without creating a new claim. A free-introduction no-show consumes the one-offer allowance.

## Bundle refund assessment

The standard software assessment values each unused paid session at the published standard price of 39 RON. The included free session is not a paid session. For a refund request, the service counts unused paid credits in the entitlement balance and paid credits attached to future confirmed appointments. Future appointments that use credits included in a full-bundle refund are placed into a refund-pending state while Stripe's result is uncertain. That state continues to block the slot and prevents the session from being consumed while the refund is unresolved.

When Stripe confirms the refund:
- The refunded paid credits remain unavailable and the associated future appointments are cancelled.
- If the refund covers the full original payment, the included free credit and any future appointment using that free credit are also revoked/cancelled.
- A cancellation notification is queued for each affected future appointment, and the refund/booking/credit changes are audited.

If Stripe confirms failure:
- Only the unbooked balance credits reserved for the refund are restored to the entitlement.
- Appointments placed into a refund-pending state are returned to confirmed; their original credit reservations remain attached.
- The failed refund, restored balances, and booking reversals are audited, and the customer receives a refund-failure notification.

The application uses Stripe idempotency keys and persists the provider refund ID, amount, currency, reason, actor, status, timestamps, and reconciliation state. An ambiguous provider result leaves the refund and affected credits/appointments pending until reconciliation; staff must not create a replacement refund or manually release the credits while the result is unknown.

The standard formula is:

**Refund amount = number of unused paid sessions being refunded × 39 RON**

For example, three unused paid sessions yield a standard assessment of 117 RON, before considering prior confirmed refunds or mandatory legal rights. The service prevents total confirmed/pending refunds from exceeding the original payment. A legal withdrawal right or other mandatory exception may require a different assessment; the production policy and any staff exception amount must be reviewed and authorized before launch. The client never supplies the refund amount.

## Staff operations and audit

The Filament booking actions support customer-request cancellation, rescheduling, teacher cancellation, and no-show recording. The entitlement action assesses/refunds unused paid credits and cancels only the future appointments affected by the refund. Every staff action requires an authenticated staff account; exception actions require a reason. The server rechecks state under the booking mutex and row locks before mutating data.

Audit records include the actor (when staff initiated the action), action, reason, accepted policy version, prior/new appointment time where relevant, credit type/outcome, entitlement, refund ID/provider status, and affected booking IDs. Credit ledger changes use unique idempotency keys. Duplicate cancellation, reschedule, no-show, webhook, or refund requests do not restore or consume a credit twice.

Customer management links are signed and expire after the appointment window. The booking is not editable through mass assignment or direct Filament record editing. A failed email/WhatsApp send does not roll back a valid booking or refund transition; the shared notification outbox retries delivery and exposes failures for audited recovery.

## Failure recovery

1. If a refund is pending/ambiguous, inspect the payment, payment_refunds row, Stripe refund ID, webhook events, and reconciliation logs. Keep refund-pending bookings and reserved credits unavailable until provider status is authoritative.
2. If Stripe reports success, reconciliation finalizes credit revocation, affected appointment cancellations, and notifications exactly once.
3. If Stripe reports failure, reconciliation restores only balance credits reserved by that refund and returns the affected bookings to confirmed.
4. If a notification fails, retry the same outbox row from Operations → Notification delivery. Do not create a second logical notification.
5. If an exception is required for legal/customer-service reasons, use an authorized staff action with a documented reason and immutable audit trail. Never edit balances or booking status directly in the database or Filament.

## Verification checklist before launch

- [ ] Romanian legal/accounting review approves the published cancellation/refund terms, statutory withdrawal rights, prepaid-session valuation, expired credits, tax/invoice treatment, and exception workflow.
- [ ] Customer-facing terms, checkout consent, and this policy use the same 24-hour/12-hour boundaries.
- [ ] Tests cover the exact boundaries, DST transitions, duplicate requests, concurrent cancellations, competing reschedules, slot contention, teacher cancellation, no-show, and expiry extension.
- [ ] Stripe test mode covers successful, failed, ambiguous, duplicate, and out-of-order refund results.
- [ ] Email and WhatsApp test deliveries verify cancellations, reschedules, teacher cancellations, refund outcomes, and retries.
- [ ] Hostinger scheduler, queue worker, and reconciliation command are verified against the production MySQL/MariaDB engine.
