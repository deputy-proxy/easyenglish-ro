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

            if (($discount['start_date'] ?? null) <= $date && ($discount['end_date'] ?? null) >= $date) {
                return $discount;
            }
        }

        return null;
    }

    public function currentPriceRon(?CarbonImmutable $at = null): int
    {
        $discount = $this->activeDiscount($at);

        return $discount === null ? $this->price_ron : (int) $discount['new_price'];
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
        $otherBundles = static::query()
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->get(['id', 'discounts']);

        foreach ($discounts as $index => $block) {
            $discount = $block['data'] ?? $block;
            $start = $discount['start_date'] ?? null;
            $end = $discount['end_date'] ?? null;
            $percentage = (int) ($discount['percentage'] ?? 0);

            if (! is_string($start) || ! is_string($end) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
                throw ValidationException::withMessages(["discounts.{$index}.end_date" => 'Each discount must have a valid date range.']);
            }
            if ($percentage < 1 || $percentage > 99) {
                throw ValidationException::withMessages(["discounts.{$index}.percentage" => 'Discount percentages must be between 1 and 99.']);
            }
            foreach ($periods as [$existingStart, $existingEnd]) {
                if ($start <= $existingEnd && $end >= $existingStart) {
                    throw ValidationException::withMessages(["discounts.{$index}.start_date" => 'Only one discount can be active at a time across all bundles.']);
                }
            }
            foreach ($otherBundles as $otherBundle) {
                foreach ($otherBundle->discounts ?? [] as $otherBlock) {
                    $otherDiscount = $otherBlock['data'] ?? $otherBlock;
                    $otherStart = $otherDiscount['start_date'] ?? null;
                    $otherEnd = $otherDiscount['end_date'] ?? null;

                    if (is_string($otherStart) && is_string($otherEnd) && $start <= $otherEnd && $end >= $otherStart) {
                        throw ValidationException::withMessages(["discounts.{$index}.start_date" => 'Only one discount can be active at a time across all bundles.']);
                    }
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
            $percentage = (int) ($discount['percentage'] ?? 0);
            $newPrice = (int) round($this->price_ron * (100 - $percentage) / 100);

            if (isset($block['data'])) {
                $discounts[$index]['data']['new_price'] = $newPrice;
            } else {
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
