# Campaign discount vouchers

## Overview and terminology

A campaign voucher is a staff-managed code for a percentage discount on exactly one lesson bundle. It is independent of a scheduled bundle discount. When a valid voucher is applied, it overrides the scheduled discount and is calculated from the bundle's regular catalog price. Discounts never stack. Without a voucher, the existing scheduled-discount calculation is unchanged.

## Configuration

Staff manage vouchers in Filament under **Payments → Discount Vouchers**. Each record contains:

- **Code:** 2–64 uppercase letters, numbers, hyphens, or underscores. Input is trimmed and normalized to uppercase; lookup is case-insensitive.
- **Campaign name** and optional description for internal identification.
- **Eligible bundle:** one BundleDefinition.
- **Discount:** an integer percentage from 1% through 99%.
- **Starts at / expires at:** optional timezone-aware timestamps, interpreted using the application timezone. The start is inclusive and expiry is exclusive.
- **Maximum redemptions:** optional positive integer. Empty means unlimited.
- **Active:** an explicit on/off switch.

Staff can inspect used redemptions and the configured limit, edit a campaign, and activate/deactivate it. Vouchers with redemption records cannot be deleted because those records are retained for auditability.

## Customer purchase flow

On the bundle purchase page, an authenticated customer can enter a code, apply it, or remove it before starting checkout. The summary displays the regular price, the voucher percentage and saving, and the final tax-inclusive RON amount. The same page and validation messages are available in Romanian and English.

Codes are rejected when unknown, inactive, not yet valid, expired, assigned to another bundle, or at capacity. If a code becomes invalid before checkout, the server stops checkout and asks the customer to review the price. Once an entitlement/purchase attempt has been created, its voucher and amount are bound to that purchase token; changing the code requires a fresh purchase attempt.

## Pricing rules

The effective price is the regular price multiplied by (100 minus the voucher percentage), divided by 100, and rounded to the nearest whole RON using the existing PHP rounding convention. For example, a 25% voucher on a 156 RON bundle saves 39 RON and results in 117 RON. Catalog prices and savings are whole RON amounts and already include applicable taxes. Stripe receives the amount multiplied by 100 minor units with inclusive tax behaviour.

## Payment and redemption lifecycle

Applying a code or opening Checkout does not count a redemption. When a purchase attempt is created, the server creates a unique reserved redemption row. Reserved rows occupy capacity so concurrent checkouts cannot both claim the final available use. The visible redemption_count is incremented only after a verified successful Stripe payment.

After payment confirmation, the entitlement is activated and the redemption row transitions to redeemed in the same database transaction. Its unique entitlement reference makes repeated webhook deliveries and reconciliation idempotent. A failed, expired, or cancelled payment releases a reservation; a retry may reserve the same voucher again if it remains valid and has capacity. A pending/ambiguous payment keeps its reservation until provider reconciliation determines the outcome.

The voucher row is locked while reserving capacity, and the unique redemption-per-entitlement constraint prevents duplicate reservation records. A reservation or redemption cannot be deleted through the admin UI.

## Historical purchase records

The entitlement voucher_code_snapshot and discount_snapshot preserve the canonical code, campaign name, percentage, and calculated saving used for the purchase. original_price_ron stores the regular catalog price and paid_amount_ron stores the final amount. The snapshot is not recomputed from a voucher that is later edited or deactivated. Redemption records retain the voucher/entitlement association and redemption timestamp. Voucher deletion is restricted once redemption records exist.

## Administration

1. Open **Payments → Discount Vouchers** in Filament.
2. Create a unique code and internal campaign name.
3. Select the one eligible bundle and enter a percentage from 1 to 99.
4. Optionally set a start, expiry, and maximum redemption count.
5. Keep the voucher active to make it available to customers.
6. Inspect used count and limit in the list; deactivate the voucher to stop new applications.

Only administrators can view or manage this resource. Existing purchases remain unchanged when a campaign is edited or deactivated.

## Operational and testing notes

- Focused tests: php artisan test --compact tests/Feature/DiscountVoucherTest.php and php artisan test --compact tests/Feature/StripeCheckoutTest.php.
- Full repository validation: composer ci:check, npm test, and npm run build, matching .github/workflows/tests.yml.
- Automated payment tests use the repository's fake Stripe gateway and do not require production credentials.
- Production Checkout remains controlled by the existing STRIPE_CHECKOUT_ENABLED flag and Stripe API/webhook configuration.
