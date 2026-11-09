# Stripe Checkout, webhook, reconciliation, and refund runbook

## Current purchase model

Customers purchase a prepaid bundle first, then schedule sessions from its activated credits through a short-lived signed URL. Bundle purchase does **not** reserve an appointment slot. The five-minute hold is an appointment-booking hold only and is not the bundle validity period or Stripe Checkout expiry. When a verified learner has already used the standalone free introduction, the inline booking journey offers the active bundles and links into this same purchase flow. Reviewing bundles, starting checkout, cancelling checkout, or returning from a failed checkout does not create a booking, slot hold, teacher assignment, free-session claim reservation, or call event. Availability is reloaded and checked again when the learner later schedules a session.

When a new entitlement is created, the server snapshots the active BundleDefinition name/description, paid amount, original catalog price, active discount payload (when present), paid-credit quantity, and included-free-credit quantity. The purchase page submits its rendered amount only as a consistency check; the server compares it with the current catalog price before creating a new entitlement. If the price changed, checkout is stopped and the customer must review the updated amount. Once an entitlement exists, its stored amount and discount snapshot remain authoritative for retries and reconciliation. See [bundle pricing and discounts](../product/bundle-pricing-and-discounts.md) for the full calculation and snapshot contract. Campaign vouchers reserve a redemption slot for a pending entitlement but increment the voucher redemption count only after verified payment. Successful activation redeems the reservation in the same database transaction; failed, expired, or cancelled payment releases it. The reservation is unique per entitlement so duplicate webhook/reconciliation processing cannot consume the same voucher twice. See [campaign discount vouchers](../product/discount-vouchers.md) for the complete lifecycle. The customer cannot submit a price or credit count. Stripe Checkout uses those persisted entitlement values for the line item; retries, webhook reconciliation, activation, and deferred free-credit grants must not recompute a historical purchase from the current catalog. Prices are integer RON amounts and Stripe receives minor units (paid_amount_ron * 100); the listed prices already include applicable taxes, so do not add an additional tax line.

## Configuration

The following values are server-side only:

- `STRIPE_KEY`: publishable key, for provider configuration where needed.
- `STRIPE_SECRET`: secret API key for Checkout Session creation, retrieval, expiry, and refunds.
- `STRIPE_WEBHOOK_SECRET`: signing secret for the exact webhook endpoint in the matching Stripe mode.
- `STRIPE_CHECKOUT_ENABLED`: defaults to `false`. Set to `true` only after contact details and legal/policy copy are final, HTTPS is live, webhook signatures are verified, and test-mode checkout/reconciliation has passed.

Keep test and live credentials separate. Never place secrets in source control, client-side JavaScript, logs, or support tickets. CI uses a fake Stripe gateway and requires no real credentials.

## Webhook setup

Configure the Stripe webhook endpoint as:

`https://your-domain.example/stripe/webhook`

Subscribe to these events:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`
- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `charge.dispute.created`
- `charge.dispute.updated`
- `charge.dispute.closed`
- `refund.created`
- `refund.updated`
- `refund.failed`
- `charge.refunded`

The endpoint validates the signature against the unmodified request body before processing. Each provider event ID is stored in `stripe_webhook_events`; duplicates are no-ops, and failed processing is recorded for retry. Do not disable signature verification to make a test webhook pass.

## Payment state and recovery

- A pending `Payment` and `BundleEntitlement` are created before redirecting to Stripe. Paid credits are not usable until a server-side Stripe session or signed webhook confirms the exact session, payment reference, entitlement, bundle, amount, currency, and paid state.
- Stripe Checkout sessions are configured to expire after 35 minutes, above Stripe's 30-minute minimum. No appointment slot is held while a customer pays for a bundle.
- The success URL is not proof of payment. The server retrieves the session from Stripe and verifies it before activating credits. A delayed/unpaid completed session remains pending until an asynchronous success/failure event or reconciliation resolves it.
- `php artisan stripe:reconcile` reconciles pending payments and refunds against Stripe's current state. The scheduler runs it every five minutes. Check scheduler/cron execution and Laravel logs before manually retrying an ambiguous payment or refund.
- API timeouts preserve the same checkout/refund idempotency key. Retry or reconcile the existing attempt instead of creating a second charge or refund.
- An expired, cancelled, or failed checkout does not create usable credits. Customer cancellation is stored distinctly from a provider-expired session. A later authoritative paid state can resolve an out-of-order event, and credit activation is idempotent.

## Refund policy and audit

Refunds are initiated by authorized staff only and require a reason. The server-side assessment values each unused **paid** session at 39 RON, including paid credits reserved by future confirmed appointments. The client never supplies the refund amount. Credits consumed by past completed sessions are not included in this standard assessment. Mandatory statutory rights may require a different result, so the published formula and any exception workflow must receive Romanian legal/accounting approval before launch; see the [cancellation, rescheduling, and refund policy](../product/cancellations-refunds-rescheduling.md).

Before calling Stripe, the service reserves the available paid/free balance credits and moves affected future appointments to refund-pending so their slots remain blocked while Stripe's result is uncertain. If Stripe confirms success, the associated future appointments are cancelled, their credits remain unavailable, and cancellation notifications are queued with audit records. A confirmed provider failure restores only the unbooked balance credits reserved for the refund and returns the affected appointments to confirmed. An ambiguous provider response keeps credits and appointments pending until reconciliation. A full refund also revokes the included free credit and cancels future appointments using it; a partial refund does not remove an otherwise eligible free credit. Refund amount, provider ID, status, reason, staff actor, affected booking IDs, and audit events are persisted.

Do not edit credit balances directly to work around a pending refund. If a Stripe Dashboard refund is detected without a matching local refund record, inspect the audit log and reconcile it with an authorized staff action; do not silently infer which credits to restore.

## Production checklist

1. Confirm the privacy notice, controller/contact details, cancellation terms, and Romanian tax/invoicing requirements have been reviewed.
2. Configure `APP_URL` to the final HTTPS domain and set Stripe test credentials.
3. Register the webhook endpoint in Stripe test mode and configure the matching `whsec_` value.
4. Send a test event and verify signature validation, event deduplication, and the `stripe_webhook_events` row.
5. Complete a test bundle purchase. Verify that the pending payment has no usable credits before provider confirmation and that the authoritative paid session activates exactly the paid credits.
6. Test delayed payment, expired Checkout Session, out-of-order events, duplicate delivery, an ambiguous API response, and a refund that succeeds/fails asynchronously.
7. Verify the `stripe:reconcile` scheduler runs and that logs contain no secrets or full payment payloads.
8. Only after the checklist passes, set `STRIPE_CHECKOUT_ENABLED=true` with live-mode credentials and the live webhook signing secret.

For local tests, use the fake gateway in `tests/Support/FakeStripeGateway.php`. Never use production credentials in CI.

## Customer account ownership

A bundle entitlement created during an authenticated checkout stores the authenticated customer's user ID in customer_user_id. Stripe webhook activation, payment reconciliation, refunds, and credit-ledger operations continue to reference the entitlement and do not replace its customer identity or historical contact snapshots. Guest checkouts remain supported. See the canonical [customer identity and record ownership model](../architecture/customer-identity-and-record-ownership.md) and the [legacy record reconciliation runbook](../operations/customer-record-reconciliation.md).


## Customer purchase and payment history

The authenticated dashboard's **My purchases** area is a read-only view over owned bundle entitlements, Payment records, PaymentRefund records, and CreditTransaction rows. Payment state comes from verified Stripe webhooks and server reconciliation, never from the entitlement status alone. The customer-facing status mapping distinguishes pending, succeeded, failed, expired, cancelled, refunded, partially refunded, and refund-pending states. A customer-cancelled Checkout attempt is stored as cancelled; a session that expires without the customer cancellation flow remains expired. A later retry creates a new Payment attempt.

Entitlement purchase snapshots include the localized bundle name/short description, included free-credit quantity, paid amount, paid-credit quantity, and locale. Legacy entitlements are backfilled from the current catalog and marked legacy_backfill; the original catalog wording at the historical purchase time cannot be reconstructed. See the [customer purchases and payments specification](../product/customer-purchases-and-payments.md) for display, credit-ledger reconciliation, authorization, and legacy limitations.

### Receipt and invoice limitations

The current application does not persist a Stripe receipt URL or invoice artifact. The customer portal therefore displays receipt/invoice unavailable and must not invent a download or construct provider URLs. Romanian invoice/tax document issuance remains a separate accounting/provider integration.

Stripe dispute events are stored in payment_disputes and linked to a local payment by its verified PaymentIntent or charge reference. The integration retrieves the current dispute from Stripe when processing each event, so out-of-order webhook delivery does not overwrite a newer provider state with stale event data. The customer portal distinguishes response-required/under-review disputes, early warnings, won/lost outcomes, prevented disputes, and disputes closed after a refund. A failed payment or refund is never treated as a chargeback. Subscribe to the dispute events listed above; otherwise the local dispute view will not receive updates.
