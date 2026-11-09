<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $bundle_definition_id
 * @property int $discount_percentage
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $max_redemptions
 * @property int $redemption_count
 * @property bool $is_active
 * @property BundleDefinition $bundle
 */
class DiscountVoucher extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'bundle_definition_id', 'discount_percentage',
        'starts_at', 'expires_at', 'max_redemptions', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'integer',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'max_redemptions' => 'integer',
            'redemption_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DiscountVoucher $voucher): void {
            $voucher->code = mb_strtoupper(trim($voucher->code));

            if ($voucher->code === '' || ! preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $voucher->code)) {
                throw ValidationException::withMessages(['code' => 'Use 2–64 letters, numbers, hyphens, or underscores.']);
            }

            $duplicateCode = static::query()->where('code', $voucher->code)
                ->when($voucher->exists, fn ($query) => $query->whereKeyNot($voucher->getKey()))
                ->exists();

            if ($duplicateCode) {
                throw ValidationException::withMessages(['code' => 'This voucher code is already in use.']);
            }

            if ($voucher->discount_percentage < 1 || $voucher->discount_percentage > 99) {
                throw ValidationException::withMessages(['discount_percentage' => 'Discount percentages must be between 1 and 99.']);
            }

            if ($voucher->starts_at !== null && $voucher->expires_at !== null && $voucher->expires_at->lessThanOrEqualTo($voucher->starts_at)) {
                throw ValidationException::withMessages(['expires_at' => 'The expiry must be later than the start date.']);
            }

            if ($voucher->max_redemptions !== null && $voucher->max_redemptions < 1) {
                throw ValidationException::withMessages(['max_redemptions' => 'Maximum redemptions must be a positive number or unlimited.']);
            }

            if ($voucher->exists && $voucher->max_redemptions !== null) {
                $occupiedSlots = $voucher->redemptions()->whereIn('status', ['reserved', 'redeemed'])->count();

                if ($voucher->max_redemptions < $occupiedSlots) {
                    throw ValidationException::withMessages(['max_redemptions' => 'The limit cannot be lower than existing reservations and redemptions.']);
                }
            }
        });
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = mb_strtoupper(trim($value));
    }

    /** @return BelongsTo<BundleDefinition, $this> */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(BundleDefinition::class, 'bundle_definition_id');
    }

    /** @return HasMany<DiscountVoucherRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountVoucherRedemption::class);
    }

    public function isValidFor(BundleDefinition $bundle, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->is_active
            && $this->bundle_definition_id === $bundle->id
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo($at))
            && ($this->expires_at === null || $this->expires_at->greaterThan($at))
            && ($this->max_redemptions === null || $this->redemption_count < $this->max_redemptions);
    }

    /** @param Builder<DiscountVoucher> $query */
    /** @param Builder<DiscountVoucher> $query
     * @return Builder<DiscountVoucher>
     */
    public function scopeForCode(Builder $query, string $code): Builder
    {
        return $query->where('code', mb_strtoupper(trim($code)));
    }
}
