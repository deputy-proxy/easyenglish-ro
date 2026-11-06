# Customer record ownership reconciliation

## Purpose

Backfill customer_user_id on historical bookings and bundle entitlements without guessing or merging customer identities.

## Preconditions

1. Deploy the ownership migration and application code.
2. Take and verify a database backup.
3. Confirm the production application is running the expected schema.
4. Run the dry-run command and retain its JSON report for review.

## Dry run

    php artisan customers:reconcile-record-ownership --report=storage/app/private/customer-ownership-dry-run.json

The command is non-mutating unless --apply is explicitly supplied. It reports linked candidates, unmatched records, ambiguous records, . It never includes names, email addresses, or phone numbers in the report.

A record is linkable only when exactly one account has the same normalized email and phone and both account contacts are verified. Bundle entitlements also require both verification timestamps on the entitlement itself. Records without sufficient evidence remain unowned.

## Review and apply

1. Review linked counts and sample IDs against the source records using an authorized operational process.
2. Investigate ambiguous and unmatched IDs manually. Do not resolve them by email-only matching, phone-only matching, account merging, or assumptions.
3. If the proposed links are approved, run:

    php artisan customers:reconcile-record-ownership --apply --report=storage/app/private/customer-ownership-applied.json

4. Rerun the dry-run command. Previously linked rows should no longer appear because the command only scans rows with null ownership.
5. Verify dashboard authorization and account isolation with the deployed application.

The command is repeatable and never overwrites an existing owner. It preserves all customer snapshots and child records.

## Rollback

The migration can be rolled back to remove the nullable owner columns and indexes, but doing so discards the backfilled owner links. Take a database backup before rollback. Do not delete or rewrite transactional snapshots as part of rollback.


## Deleted-account ownership revocation

When a customer account is deleted, the application stamps customer_ownership_revoked_at on its owned bookings and bundle entitlements before the foreign keys are set to null. The legacy verified-email-and-phone fallback excludes any record with this marker. The reconciliation command must never clear or overwrite this marker. Records orphaned by account deletion before the marker migration cannot be distinguished reliably from older guest/legacy records; review them manually and do not auto-link them based on contact details alone.
