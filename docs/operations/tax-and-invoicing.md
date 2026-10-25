# Tax, prepaid bundles, and invoicing workflow — launch draft

**Status: accountant approval required before live sales.** This document records the application's operational approach; it does not determine the business's Romanian tax status, VAT treatment, statutory invoice obligations, or e-Factura scope.

## Approved commercial model

All sessions last 15 minutes. The free introduction is a separate no-purchase path. Prepaid bundles are:

| Bundle | Entitlement | Customer price |
| --- | --- | ---: |
| Starter | 1 free + 4 paid sessions (5 total) | 156 RON, taxes included |
| Standard | 1 free + 10 paid sessions (11 total) | 390 RON, taxes included |
| Extended | 1 free + 20 paid sessions (21 total) | 780 RON, taxes included |

The standard paid-session reference value is 39 RON. The customer pays once for the bundle; booking an appointment against an existing entitlement is not a new sale. The free introduction does not create a zero-value Stripe checkout.

## Before enabling production checkout

The accountant must confirm and document:

1. The actual legal entity/sole-trader identity and tax/VAT status, including whether prices are VAT-inclusive and the wording required for the business's status.
2. The tax point and revenue-recognition/accounting treatment for prepaid bundles, expiry, unused credits, free introductory sessions, partial refunds, and full refunds.
3. Which customer and transaction types require invoices, receipts, or other documents; required invoice fields and numbering; and whether/how Romanian RO e-Factura applies to the business and transactions.
4. The statutory record-retention period and acceptable storage/retrieval process for accounting documents.
5. The treatment of Stripe fees, payout reconciliation, chargebacks, external refunds, and differences between payment date and invoice date.
6. The customer withdrawal/cancellation disclosures and how statutory rights interact with prepaid digital/service entitlements.

Record reviewer name/role, review date, approved procedure/version, and unresolved items in the launch checklist. Do not invent a VAT registration number, invoice issuer identity, or statutory period.

## Transaction and reconciliation record

For each paid bundle, preserve a traceable relationship between:

- local bundle entitlement and authoritative bundle definition/price;
- local payment record, amount in RON, currency, payment status, and Stripe Checkout/payment identifiers;
- Stripe payment confirmation, payout/reconciliation reference where used, and each refund attempt/outcome;
- invoice/receipt number, issue date, document storage reference, and any correction/credit note required by the accountant;
- credit ledger entries for grants, reservations, consumption, restorations, expiry, and refund adjustments.

Use the verified provider event/server-side reconciliation as payment evidence. A browser redirect or a sent email is not proof of payment. Do not store card numbers or authentication secrets in this record.

## Manual launch workflow (subject to approval)

1. Verify that the Stripe payment is succeeded and the bundle entitlement is activated from authoritative payment state.
2. Apply the accountant-approved invoice/receipt workflow, using the correct tax treatment and mandatory fields.
3. Store the document reference against the purchase/payment in the accounting system or approved register; do not create a second sale when an appointment consumes a credit.
4. Reconcile Stripe payments, payouts, refunds, and accounting records on the accountant-approved cadence.
5. For partial/full refunds, record the original purchase, amount and reason, provider refund identifier/status, any required invoice correction, and related credit changes. Do not promise that a refund is complete until provider state is confirmed.
6. Retain accounting records for the confirmed statutory period even if other personal data is anonymized earlier.

## Launch blockers

- [ ] Accountant confirms tax/VAT status and the correct tax-inclusive price disclosures.
- [ ] Accountant confirms prepaid bundle, free session, expiry, and refund treatment.
- [ ] Applicable invoice/receipt and RO e-Factura workflow is documented and tested.
- [ ] Legal/accounting reviewer approves published customer terms and statutory withdrawal treatment.
- [ ] Transaction reconciliation owner, cadence, and exception handling are assigned.
