<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_ro
 * @property string|null $name_en
 * @property int $price_ron
 * @property int $paid_credits
 * @property int $included_free_credits
 * @property int $sort_order
 * @property bool $featured
 * @property array<string, string>|null $short_description
 * @property array<string, string>|null $tagline
 * @property array<int, array<string, string>>|null $features
 * @property array<int, array<string, mixed>>|null $discounts
 */
class BundleDefinition extends Model
{
    protected $fillable = [
        'name', 'name_ro', 'name_en', 'price_ron', 'paid_credits', 'included_free_credits',
        'sort_order', 'is_active', 'short_description', 'tagline', 'features', 'discounts', 'featured',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'featured' => 'boolean',
            'short_description' => 'array',
            'tagline' => 'array',
            'features' => 'array',
            'discounts' => 'array',
        ];
    }

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            // Serialize bundle writes so global featured/discount exclusivity cannot race.
            static::query()->orderBy('id')->lockForUpdate()->first();

            return parent::save($options);
        });
    }

    protected static function booted(): void
    {
        static::saving(function (BundleDefinition $bundle): void {
            $bundle->validateDiscounts();
            $bundle->recalculateDiscountPrices();
        });

        static::saved(function (BundleDefinition $bundle): void {
            if ($bundle->featured) {
                static::query()->whereKeyNot($bundle->getKey())->where('featured', true)->update(['featured' => false]);
            }
        });
    }

    public function localizedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'en' ? ($this->name_en ?: $this->name_ro ?: $this->name) : ($this->name_ro ?: $this->name_en ?: $this->name);
    }

    /** @return array<string, mixed>|null */
    public function activeDiscount(?CarbonImmutable $at = null): ?array
    {
        $date = ($at ?? CarbonImmutable::now())->toDateString();

        foreach ($this->discounts ?? [] as $block) {
            $discount = $block['data'] ?? $block;
            $start = $discount['start_date'] ?? null;
            $end = $discount['end_date'] ?? null;

            if (is_string($start) && is_string($end) && $start <= $date && $end >= $date) {
                return $discount;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     original_price_ron: int,
     *     current_price_ron: int,
     *     savings_ron: int,
     *     discount_type: string|null,
     *     percentage: int|null,
     *     discount: array<string, mixed>|null
     * }
     */
    public function priceBreakdown(?CarbonImmutable $at = null): array
    {
        $originalPrice = (int) $this->price_ron;
        $discount = $this->activeDiscount($at);

        if ($discount === null) {
            return [
                'original_price_ron' => $originalPrice,
                'current_price_ron' => $originalPrice,
                'savings_ron' => 0,
                'discount_type' => null,
                'percentage' => null,
                'discount' => null,
            ];
        }

        $discountType = ($discount['discount_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
        $configuredPercentage = $discountType === 'percentage' ? (int) ($discount['percentage'] ?? 0) : null;
        $currentPrice = $discountType === 'fixed'
            ? $originalPrice - (int) ($discount['amount_ron'] ?? 0)
            : (int) round($originalPrice * (100 - (int) $configuredPercentage) / 100);
        $savings = $originalPrice - $currentPrice;
        if ($savings < 1 || $savings >= $originalPrice) {
            return [
                'original_price_ron' => $originalPrice,
                'current_price_ron' => $originalPrice,
                'savings_ron' => 0,
                'discount_type' => null,
                'percentage' => null,
                'discount' => null,
            ];
        }

        $percentage = $discountType === 'percentage'
            ? (int) round($savings / $originalPrice * 100)
            : null;

        return [
            'original_price_ron' => $originalPrice,
            'current_price_ron' => $currentPrice,
            'savings_ron' => $savings,
            'discount_type' => $discountType,
            'percentage' => $percentage,
            'discount' => $discount,
        ];
    }

    public function currentPriceRon(?CarbonImmutable $at = null): int
    {
        return $this->priceBreakdown($at)['current_price_ron'];
    }

    /** @param array<string, string>|null $value */
    public function localizedValue(?array $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($value[$locale] ?? $value['ro'] ?? $value['en'] ?? '');
    }

    private function validateDiscounts(): void
    {
        $discounts = $this->discounts ?? [];
        $periods = [];

        foreach ($discounts as $index => $block) {
            $discount = $block['data'] ?? $block;
            $start = $discount['start_date'] ?? null;
            $end = $discount['end_date'] ?? null;
            $discountType = $discount['discount_type'] ?? 'percentage';
            if (! in_array($discountType, ['percentage', 'fixed'], true)) {
                throw ValidationException::withMessages(["discounts.{$index}.discount_type" => 'Choose a supported discount type.']);
            }

            if (! is_string($start) || ! is_string($end) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start
                || CarbonImmutable::createFromFormat('!Y-m-d', $start)?->format('Y-m-d') !== $start
                || CarbonImmutable::createFromFormat('!Y-m-d', $end)?->format('Y-m-d') !== $end) {
                throw ValidationException::withMessages(["discounts.{$index}.end_date" => 'Each discount must have a valid date range.']);
            }

            if ($discountType === 'percentage') {
                $percentage = filter_var($discount['percentage'] ?? null, FILTER_VALIDATE_INT);
                if ($percentage === false || $percentage < 1 || $percentage > 99) {
                    throw ValidationException::withMessages(["discounts.{$index}.percentage" => 'Discount percentages must be between 1 and 99.']);
                }
            } else {
                $amount = filter_var($discount['amount_ron'] ?? null, FILTER_VALIDATE_INT);
                if ($amount === false || $amount < 1 || $amount >= (int) $this->price_ron) {
                    throw ValidationException::withMessages(["discounts.{$index}.amount_ron" => 'A fixed discount must be at least 1 RON and lower than the regular bundle price.']);
                }
            }

            foreach ($periods as [$existingStart, $existingEnd]) {
                if ($start <= $existingEnd && $end >= $existingStart) {
                    throw ValidationException::withMessages(["discounts.{$index}.start_date" => 'Discount periods within the same bundle cannot overlap.']);
                }
            }

            $periods[] = [$start, $end];
        }
    }

    private function recalculateDiscountPrices(): void
    {
        $discounts = $this->discounts ?? [];

        foreach ($discounts as $index => $block) {
            $discount = $block['data'] ?? $block;
            $discountType = ($discount['discount_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
            $newPrice = $discountType === 'fixed'
                ? (int) $this->price_ron - (int) ($discount['amount_ron'] ?? 0)
                : (int) round((int) $this->price_ron * (100 - (int) ($discount['percentage'] ?? 0)) / 100);
            $newPrice = max(1, $newPrice);

            if (isset($block['data'])) {
                $discounts[$index]['data']['discount_type'] = $discountType;
                $discounts[$index]['data']['new_price'] = $newPrice;
            } else {
                $discounts[$index]['discount_type'] = $discountType;
                $discounts[$index]['new_price'] = $newPrice;
            }
        }

        $this->discounts = $discounts;
    }

    /** @return HasMany<BundleEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(BundleEntitlement::class);
    }
}
