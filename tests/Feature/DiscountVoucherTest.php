<?php

use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\DiscountVoucher;
use App\Models\User;
use App\Services\Payments\DiscountVoucherService;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function createDiscountVoucher(BundleDefinition $bundle, array $overrides = []): DiscountVoucher
{
    return DiscountVoucher::query()->create(array_merge([
        'code' => 'SPRING25',
        'name' => 'Spring campaign',
        'bundle_definition_id' => $bundle->id,
        'discount_percentage' => 25,
        'is_active' => true,
    ], $overrides));
}

function createVoucherPendingEntitlement(BundleDefinition $bundle, DiscountVoucher $voucher): BundleEntitlement
{
    return BundleEntitlement::query()->create([
        'bundle_definition_id' => $bundle->id,
        'customer_name' => 'Voucher Customer',
        'customer_email' => 'voucher-customer@example.com',
        'customer_phone' => '+40722123456',
        'status' => 'pending_payment',
        'paid_amount_ron' => (int) round($bundle->price_ron * (100 - $voucher->discount_percentage) / 100),
        'original_price_ron' => $bundle->price_ron,
        'discount_snapshot' => [
            'discount_type' => 'voucher',
            'voucher_code' => $voucher->code,
            'voucher_name' => $voucher->name,
            'percentage' => $voucher->discount_percentage,
        ],
        'voucher_code_snapshot' => $voucher->code,
        'currency' => 'RON',
        'paid_credits' => $bundle->paid_credits,
        'free_credits_granted' => 0,
        'credits_remaining' => 0,
        'free_credits_remaining' => 0,
    ]);
}

it('normalizes voucher codes and calculates the discount from the regular bundle price', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $voucher = createDiscountVoucher($bundle, ['code' => ' spring25 ']);

    $quote = app(DiscountVoucherService::class)->quote($bundle, 'spring25');

    expect($voucher->fresh()->code)->toBe('SPRING25')
        ->and($quote)->toMatchArray([
            'original_price_ron' => 156,
            'current_price_ron' => 117,
            'savings_ron' => 39,
            'discount_type' => 'voucher',
            'percentage' => 25,
        ])
        ->and($quote['discount']['voucher_code'])->toBe('SPRING25');
});

it('overrides rather than stacks a scheduled bundle discount', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Reducere programată', 'en' => 'Scheduled discount'],
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'percentage' => 10,
    ]]]]);
    $voucher = createDiscountVoucher($bundle, ['discount_percentage' => 40]);

    $quote = app(DiscountVoucherService::class)->quote($bundle, $voucher->code);

    expect($bundle->currentPriceRon())->toBe(140)
        ->and($quote['current_price_ron'])->toBe(94)
        ->and($quote['savings_ron'])->toBe(62)
        ->and($quote['percentage'])->toBe(40);
});

it('rejects invalid voucher state, dates, bundle eligibility, and exhausted capacity', function (): void {
    $bundles = BundleDefinition::query()->orderBy('id')->take(2)->get();
    $service = app(DiscountVoucherService::class);

    $inactive = createDiscountVoucher($bundles[0], ['code' => 'INACTIVE', 'is_active' => false]);
    expect(fn () => $service->quote($bundles[0], $inactive->code))->toThrow(ValidationException::class);

    $future = createDiscountVoucher($bundles[0], ['code' => 'FUTURE', 'starts_at' => now()->addDay()]);
    expect(fn () => $service->quote($bundles[0], $future->code))->toThrow(ValidationException::class);

    $expired = createDiscountVoucher($bundles[0], ['code' => 'EXPIRED', 'expires_at' => now()->subSecond()]);
    expect(fn () => $service->quote($bundles[0], $expired->code))->toThrow(ValidationException::class);

    $wrongBundle = createDiscountVoucher($bundles[0], ['code' => 'OTHERBUNDLE']);
    expect(fn () => $service->quote($bundles[1], $wrongBundle->code))->toThrow(ValidationException::class);

    $limited = createDiscountVoucher($bundles[0], ['code' => 'LIMITED', 'max_redemptions' => 1]);
    $entitlement = createVoucherPendingEntitlement($bundles[0], $limited);
    $service->reserveForEntitlement($limited->code, $bundles[0], $entitlement);

    expect(fn () => $service->quote($bundles[0], $limited->code))->toThrow(ValidationException::class);

    $service->releaseForEntitlement($entitlement);
    expect($service->quote($bundles[0], $limited->code)['current_price_ron'])->toBe(117)
        ->and($limited->fresh()->redemption_count)->toBe(0);
});

it('records a redemption exactly once and releases failed reservations', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $voucher = createDiscountVoucher($bundle, ['max_redemptions' => 2]);
    $entitlement = createVoucherPendingEntitlement($bundle, $voucher);
    $service = app(DiscountVoucherService::class);

    $service->reserveForEntitlement($voucher->code, $bundle, $entitlement);
    $service->redeemForEntitlement($entitlement);
    $service->redeemForEntitlement($entitlement);

    expect($voucher->fresh()->redemption_count)->toBe(1)
        ->and($voucher->redemptions()->where('status', 'redeemed')->count())->toBe(1);

    $secondEntitlement = createVoucherPendingEntitlement($bundle, $voucher);
    $service->reserveForEntitlement($voucher->code, $bundle, $secondEntitlement);
    $service->releaseForEntitlement($secondEntitlement);

    expect($voucher->fresh()->redemption_count)->toBe(1)
        ->and($voucher->redemptions()->where('status', 'reserved')->count())->toBe(0)
        ->and($voucher->redemptions()->where('status', 'released')->count())->toBe(1);
});

it('lets customers apply and remove a voucher before checkout', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    createDiscountVoucher($bundle);
    $token = str_repeat('V', 48);

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token], 'locale' => 'en'])
        ->post(route('purchase.voucher.apply', $bundle), ['voucher_code' => 'spring25'])
        ->assertRedirect(route('purchase', $bundle));

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token], 'bundle_voucher_codes' => [$bundle->id => 'SPRING25'], 'locale' => 'en'])
        ->get(route('purchase', $bundle))
        ->assertOk()
        ->assertSee('SPRING25')
        ->assertSee('117')
        ->assertSee('39');

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token], 'bundle_voucher_codes' => [$bundle->id => 'SPRING25'], 'locale' => 'en'])
        ->delete(route('purchase.voucher.remove', $bundle))
        ->assertRedirect(route('purchase', $bundle));

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token], 'locale' => 'en'])
        ->get(route('purchase', $bundle))
        ->assertOk()
        ->assertSee('156')
        ->assertDontSee('SPRING25');
});

it('supports the full percentage range and rejects duplicate normalized codes', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    createDiscountVoucher($bundle, ['code' => 'ONEPERCENT', 'discount_percentage' => 1]);
    $ninetyNine = createDiscountVoucher($bundle, ['code' => 'NINETYNINE', 'discount_percentage' => 99]);

    expect(app(DiscountVoucherService::class)->quote($bundle, 'ONEPERCENT')['current_price_ron'])->toBe(154)
        ->and(app(DiscountVoucherService::class)->quote($bundle, $ninetyNine->code)['current_price_ron'])->toBe(2)
        ->and(fn () => createDiscountVoucher($bundle, ['code' => 'onepercent']))->toThrow(ValidationException::class);
});

it('prevents lowering a redemption limit below existing reservations', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $voucher = createDiscountVoucher($bundle, ['max_redemptions' => 2]);
    $entitlement = createVoucherPendingEntitlement($bundle, $voucher);
    $secondEntitlement = createVoucherPendingEntitlement($bundle, $voucher);
    app(DiscountVoucherService::class)->reserveForEntitlement($voucher->code, $bundle, $entitlement);
    app(DiscountVoucherService::class)->reserveForEntitlement($voucher->code, $bundle, $secondEntitlement);

    expect(fn () => $voucher->update(['max_redemptions' => 1]))->toThrow(ValidationException::class);
});

it('exposes voucher administration only to administrators', function (): void {
    $this->actingAs(User::factory()->create(['is_staff' => true, 'is_admin' => true]))
        ->get('/admin/discount-vouchers')
        ->assertOk()
        ->assertSee('Discount vouchers');

    $this->actingAs(User::factory()->create(['is_staff' => true, 'is_admin' => false]))
        ->get('/admin/discount-vouchers')
        ->assertForbidden();
});
