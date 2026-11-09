# Bundle pricing and discounts

## Product and pricing invariants

- Bundle definitions are the server-managed catalog. Public pages never accept a customer-supplied amount as a price source.
- Bundle prices are integer RON amounts and include all applicable taxes. Stripe Checkout receives the amount in RON minor units (RON multiplied by 100) with inclusive tax behaviour; no additional tax is added.
- A bundle purchase is separate from appointment scheduling. Buying a bundle does not reserve an appointment slot or create a booking.
- A verified Stripe payment activates the entitlement. A browser redirect alone is never proof of payment.
- Once an entitlement has been created for a purchase attempt, its price and discount details are historical purchase snapshots. Retries, Stripe reconciliation, webhook processing, and activation must use those snapshots rather than recomputing the amount from a catalog that may have changed.

## Catalog configuration

Bundle definitions are managed in Filament under **Payments → Lesson bundles**. The `discounts` JSON column stores one or more dated discount blocks. Each block retains its Builder `data` payload, including localized Romanian/English title and inclusive start/end dates.

Supported discount types:

- **Percentage**: `discount_type=percentage` and an integer `percentage` from 1 to 99. Existing discount blocks without `discount_type` are treated as percentage discounts for backward compatibility.
- **Fixed amount**: `discount_type=fixed` and an integer `amount_ron` greater than zero and lower than the bundle's regular price.

The model validates date ranges and discount values when a bundle is saved. Discount periods may not overlap within one bundle or across different bundles. This preserves the current product rule that at most one catalog discount is active at a time. The calculated `new_price` remains stored in the JSON payload for the admin's read-only calculated-price field, but runtime pricing is calculated from the regular price and the underlying discount inputs. A stale stored `new_price` is not authoritative.

Discount dates are calendar dates evaluated in the application's configured timezone. Both the start and end date are inclusive.

## Authoritative price calculation

`App\Models\BundleDefinition::priceBreakdown()` is the single catalog pricing calculation used by public presentation and the initial purchase snapshot. It returns:

- `original_price_ron`: regular catalog price;
- `current_price_ron`: effective price after the active discount, or the regular price when no discount applies;
- `savings_ron`: exact integer RON difference;
- `discount_type` and `percentage`: metadata for the correct saving label;
- `discount`: the active localized discount payload, or `null`.

`currentPriceRon()` delegates to this breakdown for existing callers. Percentage effective prices retain the existing round-to-nearest-RON formula: regular price multiplied by (100 minus the configured percentage), divided by 100. The displayed whole-number saving percentage is derived from the exact integer difference between regular and effective prices, not from formatted price strings. Fixed discounts subtract the configured integer RON amount. Invalid or non-price-reducing values are rejected when saved; the runtime calculation also prevents an effective price below 1 RON.

The public homepage bundle cards, the inline bundle selection shown after a learner has already used the free introduction, and the purchase summary use this shared breakdown. They show the crossed-out regular price and a localized, truthful saving indicator only when a discount is active. A percentage discount is labelled as a percentage; a fixed discount is labelled with its RON saving amount.

## Checkout consistency and tamper resistance

The purchase page includes a hidden `displayed_price_ron` value. This is **not** a price instruction. It is a consistency token representing the effective price rendered by the server.

When a new purchase attempt reaches `StripeCheckoutService::start()`:

1. The service calculates the current price breakdown on the server.
2. It compares the submitted display value with the server-calculated effective price.
3. If the values differ, it raises a localized validation error. No entitlement, payment, or Stripe Checkout Session is created. The customer must reload/review the current price and submit again.
4. If the values match, the service snapshots the effective price, regular price, and active discount payload on the new `BundleEntitlement`.
5. The `Payment` amount is copied from that entitlement snapshot. Stripe Checkout uses the persisted payment/entitlement amount, never the browser field or a newly calculated catalog price.

If the purchase token already has a pending entitlement, the purchase page displays the immutable purchase snapshot and checkout requires the submitted display value to match that snapshot. A catalog edit or discount expiry after purchase creation does not silently change the amount of an existing purchase attempt. This follows the established Stripe runbook rule that historical purchase data must not be recomputed from the live catalog.

The `original_price_ron` and `discount_snapshot` entitlement fields preserve the regular price and the exact discount payload used for the purchase. `paid_amount_ron` remains the authoritative effective amount for the entitlement, while `payments.amount_ron` records the amount for each Stripe payment attempt. Stripe metadata continues to identify the payment, entitlement, and bundle. Existing webhook verification, idempotency, and entitlement activation behaviour is unchanged.

## Featured bundle styling

The featured card keeps the same white surface, typography, content, and primary CTA as the other cards. A non-layout-affecting `::before` overlay draws the blue border and animates only its border colour and glow. The featured state remains data-driven through `BundleDefinition::featured`. Under `prefers-reduced-motion: reduce`, the animation is disabled and a static border remains.

## Relevant implementation

- `app/Models/BundleDefinition.php`: discount validation, active discount lookup, price breakdown, and calculated admin price.
- `app/Filament/Resources/BundleDefinitions/BundleDefinitionResource.php`: percentage/fixed discount configuration.
- `app/Services/Payments/StripeCheckoutService.php`: server-side displayed-price comparison and purchase snapshot creation.
- `app/Models/BundleEntitlement.php`: immutable original-price and discount snapshots.
- `app/Http/Controllers/PublicSiteController.php`: purchase display and checkout validation.
- `resources/views/public/home.blade.php`: public bundle cards.
- `resources/views/public/purchase.blade.php`: purchase summary and displayed-price consistency field.
- `resources/views/public/partials/booking-authenticated-form.blade.php`: inline bundle price presentation.
- `resources/css/app.css`: featured border animation and reduced-motion fallback.
- `tests/Feature/BundleDefinitionTest.php`: date rules, percentage/fixed calculations, exclusivity, and featured designation.
- `tests/Feature/StripeCheckoutTest.php`: Stripe amounts, purchase snapshots, stale-price rejection, and retry behaviour.

## Schema and historical records

The entitlement table now stores nullable `original_price_ron` and `discount_snapshot` columns. They are nullable because existing entitlements cannot reliably reconstruct the regular price/discount that was in effect at the time of an earlier purchase. Existing `paid_amount_ron` values remain unchanged. New purchases populate all three values.

No changes are made to the credit ledger, bundle validity, booking, cancellation, refund, tax, Stripe webhook, or free-introduction rules.
