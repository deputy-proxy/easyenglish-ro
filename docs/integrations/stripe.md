# Stripe Checkout, webhook, reconciliation, and refund runbook

## Current purchase model

Customers purchase a prepaid bundle first, then schedule sessions from its activated credits through a short-lived signed URL. Bundle purchase does **not** reserve an appointment slot. The five-minute hold is an appointment-booking hold only and is not the bundle validity period or Stripe Checkout expiry.

The purchase amount is always read from the active `BundleDefinition` on the server. The customer cannot submit a price or credit count. Prices are integer RON amounts and Stripe receives minor units (`price_ron * 100`); the listed prices already include applicable taxes, so do not add an additional tax line.

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
- `payment_intent.payment_failed`
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
- An expired or failed checkout does not create usable credits. A later authoritative paid state can resolve an out-of-order event, and credit activation is idempotent.

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
