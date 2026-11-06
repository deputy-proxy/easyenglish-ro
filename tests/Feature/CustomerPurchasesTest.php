<?php

use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Models\FreeSessionClaim;
use App\Models\Payment;
use App\Models\PaymentDispute;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Support\Str;

function makeCustomerPurchase(User $customer, BundleDefinition $bundle, array $overrides = []): BundleEntitlement
{
    return BundleEntitlement::query()->create(array_merge([
        'customer_user_id' => $customer->id,
        'bundle_definition_id' => $bundle->id,
        'bundle_name_snapshot' => $bundle->localizedName('en'),
        'bundle_description_snapshot' => $bundle->localizedValue($bundle->short_description, 'en'),
        'included_free_credits_snapshot' => $bundle->included_free_credits,
        'bundle_snapshot_source' => 'purchase',
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'locale' => 'en',
        'status' => 'active',
        'paid_amount_ron' => 156,
        'currency' => 'RON',
        'paid_credits' => 4,
        'free_credits_granted' => 1,
        'credits_remaining' => 2,
        'free_credits_remaining' => 1,
        'activated_at' => now()->subDays(2),
        'expires_at' => now()->addMonths(3),
    ], $overrides));
}

function makeCustomerPayment(BundleEntitlement $entitlement, array $overrides = []): Payment
{
    return Payment::query()->create(array_merge([
        'bundle_entitlement_id' => $entitlement->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_customer_purchase_'.$entitlement->id.'_'.Str::random(6),
        'provider_payment_id' => 'pi_private_customer_purchase_'.$entitlement->id.'_'.Str::random(6),
        'amount_ron' => $entitlement->paid_amount_ron,
        'currency' => $entitlement->currency,
        'status' => 'succeeded',
        'confirmed_at' => now()->subDays(2),
        'refunded_amount_ron' => 0,
    ], $overrides));
}

function makeCustomerCreditTransaction(BundleEntitlement $entitlement, array $overrides = []): CreditTransaction
{
    return CreditTransaction::query()->create(array_merge([
        'bundle_entitlement_id' => $entitlement->id,
        'type' => 'granted',
        'credit_type' => 'paid',
        'quantity' => 4,
        'balance_after' => 4,
        'reason' => 'Internal reason must not be shown to the customer.',
        'idempotency_key' => (string) Str::uuid(),
    ], $overrides));
}

function customerPurchaseBundle(): BundleDefinition
{
    return BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
}

test('my purchases shows purchase lifecycle, authoritative payments, refunds and credit movements without provider secrets', function () {
    $customer = User::factory()->create(['email' => 'purchases-owner@example.com']);
    $other = User::factory()->create(['email' => 'purchases-other@example.com']);
    $bundle = customerPurchaseBundle();
    $bundle->forceFill([
        'name_en' => 'Catalog Standard Bundle',
        'short_description' => ['en' => 'Catalog description at purchase time.'],
    ])->save();

    $active = makeCustomerPurchase($customer, $bundle, [
        'bundle_name_snapshot' => 'Original Standard Bundle',
        'bundle_description_snapshot' => 'Original purchase description.',
        'paid_amount_ron' => 156,
        'paid_credits' => 4,
        'included_free_credits_snapshot' => 1,
        'credits_remaining' => 2,
        'free_credits_remaining' => 1,
    ]);
    $pending = makeCustomerPurchase($customer, $bundle, [
        'status' => 'pending_payment',
        'bundle_name_snapshot' => 'Pending Bundle',
        'credits_remaining' => 0,
        'free_credits_granted' => 0,
        'free_credits_remaining' => 0,
        'activated_at' => null,
        'expires_at' => null,
    ]);
    $failedPurchase = makeCustomerPurchase($customer, $bundle, [
        'status' => 'pending_payment',
        'bundle_name_snapshot' => 'Failed Purchase',
        'credits_remaining' => 0,
        'free_credits_granted' => 0,
        'free_credits_remaining' => 0,
        'activated_at' => null,
        'expires_at' => null,
    ]);
    $failed = makeCustomerPayment($failedPurchase, ['status' => 'failed', 'confirmed_at' => null]);
    $cancelledPurchase = makeCustomerPurchase($customer, $bundle, [
        'status' => 'pending_payment',
        'bundle_name_snapshot' => 'Cancelled Checkout Bundle',
        'credits_remaining' => 0,
        'free_credits_granted' => 0,
        'free_credits_remaining' => 0,
        'activated_at' => null,
        'expires_at' => null,
    ]);
    $cancelledPayment = makeCustomerPayment($cancelledPurchase, [
        'status' => 'cancelled',
        'confirmed_at' => null,
        'provider_checkout_id' => 'cs_cancelled_'.$cancelledPurchase->id,
        'provider_payment_id' => null,
    ]);
    $expired = makeCustomerPurchase($customer, $bundle, [
        'status' => 'active',
        'bundle_name_snapshot' => 'Expired Bundle',
        'expires_at' => now()->subDay(),
    ]);
    $consumed = makeCustomerPurchase($customer, $bundle, [
        'bundle_name_snapshot' => 'Consumed Bundle',
        'credits_remaining' => 0,
        'free_credits_remaining' => 0,
    ]);
    $refunded = makeCustomerPurchase($customer, $bundle, [
        'status' => 'refunded',
        'bundle_name_snapshot' => 'Refunded Bundle',
        'credits_remaining' => 0,
        'free_credits_remaining' => 0,
    ]);
    $successfulPurchase = makeCustomerPurchase($customer, $bundle, [
        'bundle_name_snapshot' => 'Successful Payment Bundle',
    ]);
    $refundPendingPurchase = makeCustomerPurchase($customer, $bundle, [
        'bundle_name_snapshot' => 'Refund Pending Bundle',
    ]);
    $refundFailedPurchase = makeCustomerPurchase($customer, $bundle, [
        'bundle_name_snapshot' => 'Refund Failed Bundle',
    ]);
    $payment = makeCustomerPayment($active, ['refunded_amount_ron' => 39]);
    $refund = PaymentRefund::query()->create([
        'payment_id' => $payment->id,
        'amount_ron' => 39,
        'currency' => 'RON',
        'credits_to_revoke' => 1,
        'free_credits_to_revoke' => 0,
        'status' => 'succeeded',
        'reason' => 'Customer requested a partial refund.',
        'idempotency_key' => 'customer-purchase-refund-'.$payment->id,
        'confirmed_at' => now()->subDay(),
    ]);
    $pendingPayment = makeCustomerPayment($pending, [
        'status' => 'pending',
        'confirmed_at' => null,
        'provider_checkout_id' => 'cs_pending_'.$pending->id,
        'provider_payment_id' => null,
    ]);
    $expiredPayment = makeCustomerPayment($expired, [
        'status' => 'expired',
        'confirmed_at' => null,
        'provider_checkout_id' => 'cs_expired_'.$expired->id,
        'provider_payment_id' => null,
    ]);
    $failed->refresh();
    $refundedPayment = makeCustomerPayment($refunded, [
        'status' => 'refunded',
        'refunded_amount_ron' => 156,
    ]);
    $successfulPayment = makeCustomerPayment($successfulPurchase, [
        'provider_checkout_id' => 'cs_successful_'.$successfulPurchase->id,
        'provider_payment_id' => 'pi_successful_'.$successfulPurchase->id,
    ]);
    $refundPendingPayment = makeCustomerPayment($refundPendingPurchase, [
        'provider_checkout_id' => 'cs_refund_pending_'.$refundPendingPurchase->id,
        'provider_payment_id' => 'pi_refund_pending_'.$refundPendingPurchase->id,
    ]);
    PaymentRefund::query()->create([
        'payment_id' => $refundPendingPayment->id,
        'amount_ron' => 39,
        'currency' => 'RON',
        'credits_to_revoke' => 1,
        'free_credits_to_revoke' => 0,
        'status' => 'pending',
        'reason' => 'Customer requested a refund.',
        'idempotency_key' => 'customer-purchase-refund-pending-'.$refundPendingPayment->id,
    ]);
    $refundFailedPayment = makeCustomerPayment($refundFailedPurchase, [
        'provider_checkout_id' => 'cs_refund_failed_'.$refundFailedPurchase->id,
        'provider_payment_id' => 'pi_refund_failed_'.$refundFailedPurchase->id,
    ]);
    $disputedPayment = makeCustomerPayment($successfulPurchase, [
        'provider_checkout_id' => 'cs_disputed_'.$successfulPurchase->id,
        'provider_payment_id' => 'pi_disputed_'.$successfulPurchase->id,
    ]);
    PaymentDispute::query()->create([
        'payment_id' => $disputedPayment->id,
        'provider_dispute_id' => 'dp_customer_dispute',
        'provider_payment_intent_id' => $disputedPayment->provider_payment_id,
        'provider_charge_id' => 'ch_customer_dispute',
        'amount_minor' => 15600,
        'currency' => 'RON',
        'status' => 'needs_response',
        'reason' => 'fraudulent',
        'evidence_due_at' => now()->addDays(5),
        'created_at_provider' => now()->subDay(),
        'last_event_id' => 'evt_customer_dispute',
    ]);
    PaymentRefund::query()->create([
        'payment_id' => $refundFailedPayment->id,
        'amount_ron' => 39,
        'currency' => 'RON',
        'credits_to_revoke' => 1,
        'free_credits_to_revoke' => 0,
        'status' => 'failed',
        'reason' => 'Refund failed at provider.',
        'idempotency_key' => 'customer-purchase-refund-failed-'.$refundFailedPayment->id,
    ]);

    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $customer->id,
        'bundle_entitlement_id' => $active->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Purchase ledger linked session',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'duration_minutes' => 30,
        'status' => 'confirmed',
        'locale' => 'en',
    ]);

    makeCustomerCreditTransaction($active, ['type' => 'granted', 'credit_type' => 'paid', 'quantity' => 4, 'balance_after' => 4]);
    makeCustomerCreditTransaction($active, ['type' => 'reserved', 'credit_type' => 'paid', 'quantity' => -1, 'balance_after' => 2, 'booking_id' => $booking->id]);
    makeCustomerCreditTransaction($active, ['type' => 'granted', 'credit_type' => 'free', 'quantity' => 1, 'balance_after' => 1]);

    $otherPurchase = makeCustomerPurchase($other, $bundle, [
        'bundle_name_snapshot' => 'Other customer private bundle',
    ]);
    makeCustomerPayment($otherPurchase, [
        'provider_payment_id' => 'pi_other_customer_private',
        'provider_checkout_id' => 'cs_other_customer_private',
    ]);
    makeCustomerCreditTransaction($otherPurchase, ['reason' => 'Other customer private credit reason.']);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'purchases']))
        ->assertOk()
        ->assertSee('Original Standard Bundle')
        ->assertSee('Original purchase description.')
        ->assertSee('Pending Bundle')
        ->assertSee('Failed Purchase')
        ->assertSee('Cancelled Checkout Bundle')
        ->assertSee('Expired Bundle')
        ->assertSee('Consumed Bundle')
        ->assertSee('Refunded Bundle')
        ->assertSee('Successful Payment Bundle')
        ->assertSee('Refund Pending Bundle')
        ->assertSee('Refund Failed Bundle')
        ->assertSee(__('public.dashboard_purchase_status_payment_failed'))
        ->assertSee(__('public.dashboard_purchase_status_checkout_cancelled'))
        ->assertSee(__('public.dashboard_purchase_status_expired'))
        ->assertSee(__('public.dashboard_purchase_status_consumed'))
        ->assertSee(__('public.dashboard_purchase_status_refunded'))
        ->assertSee('PAY-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT))
        ->assertSee(__('public.payment_status_partially_refunded'))
        ->assertSee(__('public.payment_status_succeeded'))
        ->assertSee(__('public.payment_status_refund_pending'))
        ->assertSee(__('public.payment_status_chargeback_open'))
        ->assertSee('DSP-'.str_pad((string) PaymentDispute::query()->where('provider_dispute_id', 'dp_customer_dispute')->value('id'), 6, '0', STR_PAD_LEFT))
        ->assertSee(__('public.refund_status_failed'))
        ->assertSee('REF-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT))
        ->assertSee(__('public.dashboard_receipt_unavailable'))
        ->assertSee(__('public.dashboard_chargeback_tracking_note'))
        ->assertSee(__('public.credit_transaction_reserved'))
        ->assertSee('Purchase ledger linked session')
        ->assertDontSee('Other customer private bundle')
        ->assertDontSee('pi_private_customer_purchase')
        ->assertDontSee('pi_other_customer_private')
        ->assertDontSee('Internal reason must not be shown')
        ->assertViewHas('purchaseStatuses', fn (array $statuses): bool => $statuses[$active->id] === 'active'
            && $statuses[$pending->id] === 'pending_payment'
            && $statuses[$failedPurchase->id] === 'payment_failed'
            && $statuses[$cancelledPurchase->id] === 'checkout_cancelled'
            && $statuses[$expired->id] === 'expired'
            && $statuses[$consumed->id] === 'consumed'
            && $statuses[$refunded->id] === 'refunded')
        ->assertViewHas('paymentStatuses', fn (array $statuses): bool => $statuses[$payment->id] === 'partially_refunded'
            && $statuses[$pendingPayment->id] === 'pending'
            && $statuses[$failed->id] === 'failed'
            && $statuses[$expiredPayment->id] === 'expired'
            && $statuses[$cancelledPayment->id] === 'cancelled'
            && $statuses[$refundedPayment->id] === 'refunded'
            && $statuses[$successfulPayment->id] === 'succeeded'
            && $statuses[$refundPendingPayment->id] === 'refund_pending'
            && $statuses[$refundFailedPayment->id] === 'succeeded'
            && $statuses[$disputedPayment->id] === 'chargeback_open')
        ->assertViewHas('creditReconciliation', fn (array $reconciliation): bool => $reconciliation[$active->id] === 'matched');
});

test('my purchases shows missing legacy credit ledger data instead of claiming balances reconcile', function () {
    $customer = User::factory()->create(['email' => 'legacy-purchase@example.com']);
    $purchase = makeCustomerPurchase($customer, customerPurchaseBundle(), [
        'bundle_name_snapshot' => 'Legacy Bundle',
        'bundle_snapshot_source' => 'legacy_backfill',
        'credits_remaining' => 2,
        'free_credits_remaining' => 0,
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'purchases']))
        ->assertOk()
        ->assertSee(__('public.dashboard_purchase_legacy_snapshot_note'))
        ->assertSee(__('public.dashboard_purchase_credit_ledger_gap'))
        ->assertViewHas('creditReconciliation', fn (array $reconciliation): bool => $reconciliation[$purchase->id] === 'legacy_gap');
});

test('overview usable credit total excludes pending, expired, refunded and exhausted entitlements', function () {
    $customer = User::factory()->create(['email' => 'credit-summary@example.com']);
    $bundle = customerPurchaseBundle();

    makeCustomerPurchase($customer, $bundle, [
        'credits_remaining' => 2,
        'free_credits_remaining' => 1,
        'bundle_name_snapshot' => 'Usable bundle',
        'expires_at' => now()->addMonth(),
    ]);
    makeCustomerPurchase($customer, $bundle, [
        'credits_remaining' => 4,
        'free_credits_remaining' => 0,
        'bundle_name_snapshot' => 'Expired bundle',
        'expires_at' => now()->subDay(),
    ]);
    makeCustomerPurchase($customer, $bundle, [
        'credits_remaining' => 0,
        'free_credits_remaining' => 0,
        'bundle_name_snapshot' => 'Exhausted bundle',
    ]);
    makeCustomerPurchase($customer, $bundle, [
        'status' => 'pending_payment',
        'credits_remaining' => 0,
        'free_credits_remaining' => 0,
        'bundle_name_snapshot' => 'Pending bundle',
    ]);
    makeCustomerPurchase($customer, $bundle, [
        'status' => 'refunded',
        'credits_remaining' => 0,
        'free_credits_remaining' => 0,
        'bundle_name_snapshot' => 'Refunded bundle',
    ]);

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('>3<', false)
        ->assertSee('Usable bundle')
        ->assertDontSee('Expired bundle')
        ->assertDontSee('Exhausted bundle')
        ->assertDontSee('Pending bundle')
        ->assertDontSee('Refunded bundle');
});

test('my purchases paginates purchase payment and credit histories independently', function () {
    $customer = User::factory()->create(['email' => 'purchase-pagination@example.com']);
    $bundle = customerPurchaseBundle();

    foreach (range(1, 18) as $number) {
        $purchase = makeCustomerPurchase($customer, $bundle, [
            'bundle_name_snapshot' => 'Purchase history '.$number,
            'created_at' => now()->subDays($number),
        ]);
        makeCustomerPayment($purchase, [
            'provider_checkout_id' => 'cs_purchase_page_'.$number,
            'provider_payment_id' => 'pi_purchase_page_'.$number,
        ]);
        makeCustomerCreditTransaction($purchase, [
            'idempotency_key' => 'credit-page-'.$number,
        ]);
    }

    $this->actingAs($customer)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'purchases']))
        ->assertOk()
        ->assertViewHas('purchaseEntitlements', fn ($purchases): bool => $purchases->total() === 18 && $purchases->count() === 10)
        ->assertViewHas('paymentHistory', fn ($payments): bool => $payments->total() === 18 && $payments->count() === 10)
        ->assertViewHas('creditHistory', fn ($credits): bool => $credits->total() === 18 && $credits->count() === 15);

    $this->get(route('dashboard', ['view' => 'purchases', 'purchases_page' => 2]))
        ->assertOk()
        ->assertSee('Purchase history 1');
    $this->get(route('dashboard', ['view' => 'purchases', 'payments_page' => 2]))
        ->assertOk()
        ->assertSee('PAY-');
    $this->get(route('dashboard', ['view' => 'purchases', 'credits_page' => 2]))
        ->assertOk()
        ->assertSee(__('public.credit_transaction_granted'));
});

test('deferred included free credits use the entitlement snapshot after the catalog is edited', function () {
    $customer = User::factory()->create([
        'email' => 'snapshot-free-credit@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $bundle = customerPurchaseBundle();
    $purchase = makeCustomerPurchase($customer, $bundle, [
        'customer_email_verified_at' => now(),
        'customer_phone_verified_at' => now(),
        'included_free_credits_snapshot' => 1,
        'free_credits_granted' => 0,
        'free_credits_remaining' => 0,
    ]);
    $bundle->forceFill(['included_free_credits' => 0])->save();

    $claim = FreeSessionClaim::query()->create([
        'customer_email' => $customer->email,
        'customer_phone' => $customer->phone,
        'status' => 'reserved',
        'verified_at' => now(),
    ]);
    $startsAt = now()->addDay();
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => $customer->phone,
        'english_level' => 'B1',
        'session_goal' => 'Standalone introduction hold',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'pending',
        'hold_expires_at' => now()->subMinute(),
        'free_introduction' => true,
        'free_session_claim_id' => $claim->id,
        'locale' => 'en',
    ]);
    $claim->update(['booking_id' => $booking->id]);

    expect(app(BookingService::class)->expireHolds())->toBe(1)
        ->and($purchase->fresh()->free_credits_granted)->toBe(1)
        ->and($purchase->fresh()->free_credits_remaining)->toBe(1)
        ->and(FreeSessionClaim::query()->where('bundle_entitlement_id', $purchase->id)->where('status', 'bundle_credit_granted')->exists())->toBeTrue();
});

test('a new account cannot reclaim purchase and booking history after the original account is deleted', function () {
    $original = User::factory()->create([
        'email' => 'deleted-customer@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $purchase = makeCustomerPurchase($original, customerPurchaseBundle(), [
        'customer_email_verified_at' => now(),
        'customer_phone_verified_at' => now(),
        'bundle_name_snapshot' => 'Private deleted-account bundle',
    ]);
    $startsAt = now()->addDay();
    $booking = Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'customer_user_id' => $original->id,
        'bundle_entitlement_id' => $purchase->id,
        'customer_name' => $original->name,
        'customer_email' => $original->email,
        'customer_phone' => $original->phone,
        'english_level' => 'B1',
        'session_goal' => 'Private deleted-account session',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'locale' => 'en',
    ]);

    $original->delete();
    $newAccount = User::factory()->create([
        'email' => 'deleted-customer@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    expect($purchase->fresh()->customer_user_id)->toBeNull()
        ->and($purchase->fresh()->customer_ownership_revoked_at)->not->toBeNull()
        ->and($booking->fresh()->customer_user_id)->toBeNull()
        ->and($booking->fresh()->customer_ownership_revoked_at)->not->toBeNull()
        ->and(BundleEntitlement::query()->ownedBy($newAccount)->whereKey($purchase->id)->exists())->toBeFalse()
        ->and(Booking::query()->ownedBy($newAccount)->whereKey($booking->id)->exists())->toBeFalse();

    $this->actingAs($newAccount)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'purchases']))
        ->assertOk()
        ->assertDontSee('Private deleted-account bundle');
});
