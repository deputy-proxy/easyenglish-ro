# Filament resource deletion audit

Issue: [#277](https://github.com/deputy-proxy/easyenglish-ro/issues/277)

The active admin panel discovers resources from `app/Filament/Resources` through `AdminPanelProvider`. The audit found 19 registered resource classes and no Filament relation-manager classes or separate nested record tables. The teacher form contains relationship repeaters for availability rules and exceptions; these are form components, not relation-manager tables.

## Audit results

| Resource | Per-record Delete action | Authorization / domain decision |
| --- | --- | --- |
| AvailabilityExceptions | Present | Standard Filament delete authorization |
| AvailabilityRules | Present | Standard Filament delete authorization |
| BookingAudits | Not offered | Explicit `canDelete() === false`; immutable operational audit history |
| Bookings | Not offered | Explicit `canDelete() === false`; lifecycle must use audited booking workflows |
| BundleDefinitions | Present, conditional | Hidden while any entitlement references the definition |
| BundleEntitlements | Not offered | Explicit `canDelete() === false`; purchased credits and entitlement history must remain intact |
| CallEvents | Not offered | Explicit `canDelete() === false`; provider scheduling/reconciliation history |
| CreditTransactions | Not offered | Explicit `canDelete() === false`; append-only credit ledger |
| Customers | Not offered | Explicit `canDelete() === false`; customer account and ownership records |
| Faqs | Present | Explicit `canDelete() === true` |
| Messages | Not offered | Explicit `canDelete() === false`; inbound/outbound message history |
| NotificationDeliveries | Not offered | Explicit `canDelete() === false`; delivery and retry audit history |
| Payments | Not offered | Explicit `canDelete() === false`; payment-provider and accounting records |
| ProgressReports | Present | Explicit `canDelete() === true` |
| Refunds | Not offered | Explicit `canDelete() === false`; refund-provider and accounting records |
| SessionFeedback | Present | Explicit `canDelete() === true` |
| Teachers | Not offered | Explicit `canDelete() === false`; teacher accounts have scheduling and booking dependencies |
| Testimonials | Present | Explicit `canDelete() === true` |
| Users | Not offered | Explicit `canDelete() === false`; account records must not be deleted through this generic admin table |

## Outcome

All seven resources whose current domain authorization permits deletion already expose a native Filament per-record Delete action. Bundle definitions additionally hide deletion while entitlements reference them. The remaining twelve resources explicitly deny deletion and intentionally do not expose a Delete action.

No resource was made deletable as part of this audit. Doing so would weaken existing business and audit protections. No bulk-delete behavior was added. There are no relation-manager tables to update.

## Regression coverage

`tests/Feature/FilamentResourceDeletionTest.php` verifies:

- all seven currently deletable resources register a per-record Delete action;
- all twelve protected resources continue to omit the Delete action;
- the protected resources' explicit `canDelete()` decisions remain false.

If future product requirements change deletion policy for a protected resource, update its domain workflow, authorization, relationship handling, and tests deliberately rather than removing its guard.


## Deletion authorization

Filament panel access remains available to staff accounts (`is_staff`). Deletion is a separate capability and is restricted to accounts with `is_admin = true`. Both per-record and bulk deletion are authorized server-side by the resource `canDelete` / `canDeleteAny` methods. The administrator flag defaults to false; the initial migration grants it only to `admin@contes.ro`. Admins can grant or revoke the flag in user management. Setting administrator access also ensures staff panel access remains enabled.
