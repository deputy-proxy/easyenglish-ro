<?php

use App\Models\BundleDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

it('uses the active discount price and ignores discounts outside their date range', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Ofertă specială', 'en' => 'Special offer'],
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'percentage' => 25,
    ]]]]);

    expect($bundle->fresh()->currentPriceRon(CarbonImmutable::parse('2026-11-15')))->toBe(117)
        ->and($bundle->fresh()->currentPriceRon(CarbonImmutable::parse('2026-12-01')))->toBe(156)
        ->and($bundle->fresh()->discounts[0]['data']['new_price'])->toBe(117);
});

it('rejects overlapping discount periods within the same bundle', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->discounts = [
        ['type' => 'discount', 'data' => ['title' => ['ro' => 'Prima', 'en' => 'First'], 'start_date' => '2026-11-01', 'end_date' => '2026-11-20', 'percentage' => 10]],
        ['type' => 'discount', 'data' => ['title' => ['ro' => 'A doua', 'en' => 'Second'], 'start_date' => '2026-11-20', 'end_date' => '2026-11-30', 'percentage' => 15]],
    ];

    expect(fn () => $bundle->save())->toThrow(ValidationException::class);
});

it('allows overlapping discount periods across different bundles', function (): void {
    $bundles = BundleDefinition::query()->orderBy('id')->take(2)->get();
    $bundles[0]->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Prima', 'en' => 'First'],
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-20',
        'percentage' => 10,
    ]]]]);

    $bundles[1]->discounts = [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'A doua', 'en' => 'Second'],
        'start_date' => '2026-11-15',
        'end_date' => '2026-11-30',
        'percentage' => 15,
    ]]];

    expect($bundles[1]->save())->toBeTrue()
        ->and($bundles[1]->fresh()->discounts[0]['data']['percentage'])->toBe(15);
});

it('keeps only one featured bundle globally', function (): void {
    $bundles = BundleDefinition::query()->orderBy('id')->take(2)->get();
    $bundles[0]->update(['featured' => true]);
    $bundles[1]->update(['featured' => true]);

    expect(BundleDefinition::query()->where('featured', true)->count())->toBe(1)
        ->and($bundles[0]->fresh()->featured)->toBeFalse()
        ->and($bundles[1]->fresh()->featured)->toBeTrue();
});

it('returns the bundle name and marketing content in the selected locale', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();

    expect($bundle->localizedName('ro'))->toBe('Pachet 1')
        ->and($bundle->localizedName('en'))->toBe('Bundle 1')
        ->and($bundle->localizedValue($bundle->short_description, 'en'))->toContain('free session')
        ->and($bundle->localizedValue($bundle->features[0]['data'], 'en'))->toBe('15 minutes per session');
});

it('calculates fixed discounts and exposes accurate savings metadata', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Reducere fixă', 'en' => 'Fixed saving'],
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'discount_type' => 'fixed',
        'amount_ron' => 36,
    ]]]]);

    expect($bundle->fresh()->priceBreakdown(CarbonImmutable::parse('2026-11-15')))
        ->toMatchArray([
            'original_price_ron' => 156,
            'current_price_ron' => 120,
            'savings_ron' => 36,
            'savings_percentage' => 23,
            'discount_type' => 'fixed',
            'percentage' => null,
        ]);
});

it('rejects fixed discounts equal to or greater than the regular bundle price', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->discounts = [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Reducere invalidă', 'en' => 'Invalid saving'],
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'discount_type' => 'fixed',
        'amount_ron' => 156,
    ]]];

    expect(fn () => $bundle->save())->toThrow(ValidationException::class);
});

it('does not expose a corrupted discount that cannot reduce the bundle price', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->setRawAttributes(array_merge($bundle->getAttributes(), [
        'discounts' => json_encode([['type' => 'discount', 'data' => [
            'title' => ['ro' => 'Reducere invalidă', 'en' => 'Invalid saving'],
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'discount_type' => 'fixed',
            'amount_ron' => 200,
        ]]]),
    ]), true);
    $bundle->exists = true;

    expect($bundle->priceBreakdown(CarbonImmutable::parse('2026-11-15')))
        ->toMatchArray([
            'original_price_ron' => 156,
            'current_price_ron' => 156,
            'savings_ron' => 0,
            'savings_percentage' => null,
            'discount_type' => null,
            'discount' => null,
        ]);
});

it('renders the featured bundle with a subtle border and localized discount savings', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update([
        'featured' => true,
        'discounts' => [['type' => 'discount', 'data' => [
            'title' => ['ro' => 'Ofertă specială', 'en' => 'Special offer'],
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'percentage' => 25,
        ]]],
    ]);

    $this->withSession(['locale' => 'en'])->get(route('home'))
        ->assertOk()
        ->assertSee('featured-bundle-card', false)
        ->assertDontSee('Special offer')
        ->assertDontSee('Featured')
        ->assertSee(__('public.discount_save_percentage', ['value' => 25]))
        ->assertSee('156 RON')
        ->assertSee('117 RON');

    $styles = file_get_contents(resource_path('css/app.css'));
    expect($styles)->toContain('.featured-bundle-card {')
        ->and($styles)->toContain('var(--color-primary)');

    $content = $this->get(route('home'))->getContent();
    expect(strpos($content, 'Bundle 1'))->toBeLessThan(strpos($content, '156 RON'))
        ->and(strpos($content, '156 RON'))->toBeLessThan(strpos($content, __('public.discount_save_percentage', ['value' => 25])))
        ->and(strpos($content, __('public.discount_save_percentage', ['value' => 25])))->toBeLessThan(strpos($content, '117 RON'));
});

it('preserves the established percentage-price rounding and derives the displayed saving from exact prices', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update([
        'price_ron' => 5,
        'discounts' => [['type' => 'discount', 'data' => [
            'title' => ['ro' => 'Reducere', 'en' => 'Saving'],
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'percentage' => 50,
        ]]],
    ]);

    expect($bundle->fresh()->priceBreakdown(CarbonImmutable::parse('2026-11-15')))
        ->toMatchArray([
            'original_price_ron' => 5,
            'current_price_ron' => 3,
            'savings_ron' => 2,
            'savings_percentage' => 40,
            'percentage' => 40,
        ]);
});

it('renders fixed discount savings as an effective percentage on the public bundle card', function (): void {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Reducere fixă', 'en' => 'Fixed saving'],
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'discount_type' => 'fixed',
        'amount_ron' => 36,
    ]]]]);

    $this->withSession(['locale' => 'en'])
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('Fixed saving')
        ->assertSee(__('public.discount_save_percentage', ['value' => 23]))
        ->assertDontSee('Save 36 RON')
        ->assertSee('156 RON')
        ->assertSee('120 RON');
});
