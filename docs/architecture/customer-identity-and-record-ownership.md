# Customer identity and record ownership

## Canonical ownership

users.id is the durable identity for an authenticated customer. The following transactional aggregates have a nullable customer_user_id foreign key:

- bookings.customer_user_id
- bundle_entitlements.customer_user_id

Both foreign keys use ON DELETE SET NULL. Before a customer account is deleted, the application stamps customer_ownership_revoked_at on its bookings and bundle entitlements. Legacy email/phone fallback queries require this marker to be null, so a new account reusing the same verified contacts cannot reclaim records from an account deleted after this marker was deployed. The original customer name, email, and phone snapshots remain on bookings and entitlements and are not rewritten when a profile changes.

Payments and credit transactions belong to a bundle entitlement. Session feedback and progress reports belong to a booking. Free-session claims normally belong to a booking or bundle entitlement. These records therefore inherit customer ownership through their existing parent relationships; they do not duplicate the customer foreign key. Orphaned legacy free-session claims without either parent are considered by the dashboard only when both the account email and phone are verified and exactly match the stored snapshots.

Teacher ownership remains separate in bookings.teacher_id.

## Write paths

Authenticated booking creation records the authenticated user's ID on the booking. Authenticated bundle checkout records the authenticated user on the entitlement. A checkout request key already associated with another account cannot be claimed by the current account.

Guest bookings and purchases remain valid and retain a null customer owner. This supports the public guest journey and historical records.

## Legacy reconciliation

The customers:reconcile-record-ownership command is dry-run by default. A record is eligible only when exactly one user matches both its normalized email and normalized phone and that user's email and phone are verified.

For bundle entitlements, both verification timestamps captured on the entitlement must also be present. Bookings do not contain historical verification timestamps, so the command requires both contacts to be verified on the matching account and an exact match against both transactional snapshots. Ambiguous and unmatched records are never linked automatically.

The command reports aggregate counts and record IDs only; it does not expose customer contact details. Verified user identities are loaded once for the reconciliation run to avoid one user lookup per historical record. Review the dry-run report before using --apply. The operation is repeatable and never overwrites an existing owner.

## Read authorization and legacy fallback

Customer queries use customer_user_id first. For eligible legacy records with a null owner, the compatibility fallback requires a verified account email, a verified account phone, and exact matches for both the normalized email and phone snapshot. Bundle entitlements additionally require their stored email and phone verification timestamps.

Once a record has an explicit owner, email or phone changes do not detach it from the account and another account cannot claim it through the fallback.

Call-link authorization follows the same explicit-owner rule, with the strict legacy fallback only for unowned records. Teacher access remains governed independently by teacher assignment.

## Account deletion and anonymization

Deleting a customer account sets customer_user_id to null while preserving booking and entitlement snapshots and all payment, refund, credit-ledger, feedback, and progress-report audit relationships. This avoids cascading deletion of financial and instructional history. The deleted account cannot access those records. A future account using the same contact details must not inherit them automatically. Records orphaned by account deletion before the revocation marker was deployed cannot be distinguished reliably from older guest/legacy records; they require manual review and must not be auto-linked without additional evidence.

This is a technical retention policy, not a substitute for the published privacy notice and legally reviewed retention schedule. Any anonymization of transactional snapshots must be a separate, audited retention operation that preserves statutory financial records and referential integrity.

## Schema and performance

Both owner columns are nullable for guest and legacy compatibility, indexed for account-scoped reads, and protected by foreign keys. Booking ownership is additionally indexed with the scheduled start time; entitlement ownership is indexed with status and expiry.
