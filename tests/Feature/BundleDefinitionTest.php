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

it('rejects overlapping discounts across different bundles', function (): void {
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

    expect(fn () => $bundles[1]->save())->toThrow(ValidationException::class);
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
