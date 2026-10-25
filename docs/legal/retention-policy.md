# Data retention and deletion policy — launch draft

**Status: proposed schedule; legal/accounting review required.** This document is an operational proposal, not a claim that a particular statutory retention period has been verified. Obtain Romanian legal and accountant approval before enabling automatic deletion or publishing customer-facing promises.

## Principles

- Collect only data needed to arrange and deliver the service, maintain payment integrity, meet legal duties, and protect the service.
- Keep session goals and optional notes separate from records needed to prove bookings, payments, refunds, or staff actions.
- Do not delete financial records, credit-ledger entries, or audit evidence merely to satisfy a shorter content-retention period.
- Use anonymization where full deletion would break financial reconciliation or audit integrity; retain only the minimum identifying detail required by an approved legal basis.
- Retention operations must be idempotent, logged by counts and run identifiers, and must not log the values being removed.

## Proposed category schedule

| Data category | Proposed rule | Approval / safeguards |
| --- | --- | --- |
| Session goal and optional notes | Clear/anonymize 12 months after the scheduled session date | Proposed default only; approve legal basis and exceptions before enabling automation |
| Booking/contact details | Retain only while needed for scheduling, customer support, disputes, or another documented lawful purpose; then delete or anonymize | Define exact period and handling of cancelled/no-show bookings before launch |
| Free-session eligibility claims | Retain the minimum normalized email/phone and state needed to enforce one free offer per person | Approve period and lawful basis; do not erase while needed to enforce an active eligibility rule |
| Bundle entitlements and credit ledger | Retain through bundle validity, customer disputes/refunds, and any approved accounting/audit period | Preserve ledger integrity; do not delete individual ledger rows to anonymize session notes |
| Payment, Stripe identifiers, refund records, invoice/receipt references | Retain for the applicable statutory/accounting period confirmed by the accountant | Do not guess the statutory period; keep references needed for reconciliation |
| Notification delivery records | Retain minimal channel/event/status, attempt timestamps, provider IDs and redacted failure details for an approved operational period | Never retain message bodies or credentials unnecessarily; define final period with reviewer |
| Booking and staff audit records | Retain for an approved period required for security, dispute handling, and financial integrity | Keep audit evidence append-only where possible; avoid copying notes or contact details into metadata |
| Technical/application logs | Use short rotation and minimal personal data; remove secrets immediately if logged | Configure log rotation and access controls; never log credentials or full provider payloads containing personal data |
| Backups | Expire under a documented backup rotation; restored backups must re-enter normal deletion processing | Set and verify a specific backup retention schedule before launch |

## Session-goal and notes anonymization operation

The future `bookings:anonymize-old-session-notes` command is designed to:

- select bookings whose scheduled start is at least 12 calendar months old;
- clear only `session_goal` and `notes`;
- preserve the booking reference, contact and financial records, accepted policy version, status, timestamps, and audit/credit/payment relationships;
- default to dry-run, require `--apply` plus `DATA_RETENTION_ENABLED=true` for writes, and report counts only;
- be scheduled only after the retention schedule and legal basis have been approved.

A documented exception process must exist for litigation holds, complaints, open refunds, or other approved reasons to retain relevant information. The initial implementation does not infer such exceptions from free-form notes; define and implement a structured hold mechanism before production activation if the reviewer requires one.

## Approval checklist

- [ ] Controller identity, purposes, and legal bases approved by privacy/legal reviewer.
- [ ] Exact periods approved for every category above, including backup and technical logs.
- [ ] Accounting records and Romanian invoicing/e-Factura obligations confirmed by accountant.
- [ ] Subject-access/erasure/objection request workflow and response owner documented.
- [ ] Exceptions/legal holds and staff authorization documented.
- [ ] Retention command tested against boundary dates, repeated runs, and preservation of financial/audit records.
- [ ] `DATA_RETENTION_ENABLED` remains false until sign-off is recorded.
