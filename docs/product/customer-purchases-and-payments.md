# Customer purchases, payments, and credit history

## Customer experience

The authenticated dashboard exposes **My purchases** as a dedicated area. It contains three independently paginated histories:

- Bundle purchases: ten entitlements per page.
- Payment attempts and outcomes: ten payment records per page.
- Credit movements: fifteen ledger records per page.

Every query is scoped through BundleEntitlement::ownedBy() and the durable customer account relationship. Payment and credit records inherit ownership through their entitlement. Guest or legacy records are visible only through the strict verified-contact fallback already documented in the customer identity model.

## Purchase snapshots

A new entitlement stores the bundle name and localized short description shown at checkout, the included-free-credit quantity, and a snapshot-source marker. The existing paid amount, currency, paid-credit quantity, free-credit grants, remaining balances, activation time, and expiry time are also stored on the entitlement.

The Stripe Checkout line item uses the entitlement snapshots for its product name/description and the persisted paid amount. Payment activation and deferred free-credit granting use the entitlement's persisted paid-credit and included-free-credit values; editing the current bundle catalog does not rewrite an existing purchase or change its purchased quantities.

For pre-existing entitlements, the migration backfills name, description, and included-free-credit count from the current bundle definition and marks the source as legacy_backfill. The original catalog values at the historical purchase time were not stored, so those values cannot be reconstructed reliably. The customer UI explicitly identifies these purchases as legacy snapshots. Their persisted paid amount and paid-credit quantity remain the original transactional values.

## Purchase status

The purchase status is derived from the entitlement and its latest authoritative payment attempt:

- active: entitlement is active, unexpired, and has a positive paid/free balance.
- consumed: entitlement is active and its remaining paid/free balances are both zero.
- expired: an active entitlement's expiry timestamp has passed, or the entitlement is explicitly expired.
- payment confirmed, activation pending: a verified successful Payment exists but the entitlement has not yet been activated. This is shown separately rather than misreporting a later failed retry as the final purchase state.
- pending payment: the entitlement is pending and its latest payment is still pending or no payment attempt is recorded.
- payment failed: the latest payment attempt is failed.
- checkout expired: Stripe confirmed that the Checkout Session expired.
- checkout cancelled: the customer returned through the Checkout cancellation flow and the server confirmed that the session was expired.
- refunded: the entitlement has been fully refunded.

A pending entitlement has zero usable credits. Failed, expired, and cancelled attempts remain in payment history. A later retry creates a separate payment attempt; it does not overwrite the previous attempt. A late authoritative success can still resolve a terminal attempt through the existing Stripe reconciliation rules.

## Payment history

The payment list is sourced from the Payment records created by the checkout service and updated only through verified Stripe webhooks or server-side reconciliation. Each row shows an internal safe reference (PAY- plus the local payment ID), bundle snapshot name, recorded amount/currency, payment/attempt timestamp, and a customer-facing status.

The status mapping distinguishes pending, succeeded, failed, expired, cancelled, refunded, partially refunded, and refund pending. A partial refund is identified from the persisted refunded amount; a pending/processing PaymentRefund takes precedence while the refund result is uncertain. Refund rows show their local REF- reference, amount/currency, status, and recorded timestamp. Internal provider IDs, Checkout URLs, API errors, refund reasons, webhook payloads, and secrets are never rendered.

Amounts are displayed as recorded in RON. Stripe Checkout uses tax-inclusive pricing; the customer portal does not calculate or invent a separate VAT breakdown.

## Receipts, invoices, and disputes

The current payment schema does not persist a Stripe receipt URL, invoice PDF, invoice number, or invoice artifact. The portal therefore shows an honest unavailable state and does not create a fake PDF or guess a Stripe URL. Romanian invoice/tax document issuance remains a separate accounting/provider integration and launch requirement.

Stripe dispute events are stored in payment_disputes and linked to the authoritative Payment through the provider PaymentIntent or charge reference. The latest provider dispute state is shown separately from payment/refund status: open, early warning, won, lost, prevented, or closed after refund. The UI does not infer a dispute from a failed payment or refund. Stripe dispute webhook events must be enabled for the state to appear; if the provider has not sent a dispute event, no dispute status is claimed.

## Credit ledger

Credit history is sourced from CreditTransaction, not reconstructed from booking status. Customer-facing labels are mapped from the stored transaction type and paid/free credit type. The UI shows the timestamp, signed quantity, balance after the movement, and a link to the related booking when the transaction references a booking owned by the same entitlement. Internal reason text and staff actor information are not exposed.

The ledger currently records grants, reservations, booking confirmation/consumption, restorations, forfeitures, manual adjustments, refund reservations, confirmed refunds, revocations, and release of reservations after a failed refund. Reservation quantity reduces the available balance; the later consumed transaction records confirmation with zero additional quantity. A refund reservation is not presented as a completed refund until Stripe confirms the provider result.

For each displayed entitlement, the portal compares the latest recorded paid/free balance_after values with the current entitlement balances. Missing ledger rows are acceptable only when the corresponding current balance is zero. A mismatch or missing history for a non-zero balance is labelled as a legacy ledger gap; the persisted entitlement balance remains the authoritative current balance. This is a consistency indicator, not a claim that every historical transaction can be reconstructed.

## Overview credit summary

The dashboard overview sums only entitlements whose persisted status is active, whose expiry is null or in the future, and whose combined paid/free remaining balance is positive. Pending, expired, refunded, and exhausted entitlements never contribute usable credits.

## Privacy and authorization

- Customer purchases are scoped to the authenticated account using BundleEntitlement::ownedBy().
- Payments, refunds, and credit transactions are retrieved only through those owned entitlement IDs.
- Related booking links use the existing signed management route; they do not expose booking records by public reference alone.
- Provider checkout/payment/refund/dispute IDs, receipt secrets, internal errors, and staff reasons are not shown.
- Pagination is independent for purchases, payments, and credit movements.

## Operational and accounting gaps

1. The legacy snapshot migration freezes the best available current catalog name/description and flags it as a backfill; it cannot recover historical catalog wording.
2. Stripe receipt URLs and invoice artifacts are not stored; invoice/tax document issuance remains a separate accounting/provider integration.
4. Credit ledger gaps are identified, but the portal does not invent missing historical movements. Any manual correction must use the existing audited staff adjustment workflow.
5. Tax-inclusive payment amounts are displayed as stored; VAT breakdown/invoice issuance requires accounting-approved implementation.

See the [Stripe runbook](../integrations/stripe.md), [booking and availability model](booking-and-availability.md), and [cancellation/refund policy](cancellations-refunds-rescheduling.md).
