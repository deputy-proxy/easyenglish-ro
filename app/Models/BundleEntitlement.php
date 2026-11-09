<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $customer_user_id
 * @property CarbonImmutable|null $customer_ownership_revoked_at
 * @property string $customer_email
 * @property string $customer_phone
 * @property CarbonImmutable|null $customer_email_verified_at
 * @property CarbonImmutable|null $customer_phone_verified_at
 * @property int $bundle_definition_id
 * @property string|null $bundle_name_snapshot
 * @property string|null $bundle_description_snapshot
 * @property int $included_free_credits_snapshot
 * @property string $bundle_snapshot_source
 * @property string $status
 * @property string $locale
 * @property int $paid_amount_ron
 * @property int|null $original_price_ron
 * @property array<string, mixed>|null $discount_snapshot
 * @property string|null $voucher_code_snapshot
 * @property string $currency
 * @property int $paid_credits
 * @property int $credits_remaining
 * @property int $free_credits_remaining
 * @property int $free_credits_granted
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $expires_at
 * @property BundleDefinition $definition
 */
class BundleEntitlement extends Model
{
    public function setCustomerPhoneAttribute(string $value): void
    {
        $this->attributes['customer_phone'] = PhoneNumber::normalize($value);
    }

    protected $fillable = ['customer_user_id', 'bundle_definition_id', 'bundle_name_snapshot', 'bundle_description_snapshot', 'included_free_credits_snapshot', 'bundle_snapshot_source', 'checkout_request_key', 'customer_name', 'customer_email', 'customer_phone', 'locale', 'customer_email_verified_at', 'customer_phone_verified_at', 'status', 'paid_amount_ron', 'original_price_ron', 'discount_snapshot', 'voucher_code_snapshot', 'currency', 'paid_credits', 'free_credits_granted', 'credits_remaining', 'free_credits_remaining', 'activated_at', 'expires_at'];

    protected function casts(): array
    {
        return ['customer_email_verified_at' => 'immutable_datetime', 'customer_phone_verified_at' => 'immutable_datetime', 'customer_ownership_revoked_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'discount_snapshot' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /**
     * @param  Builder<BundleEntitlement>  $query
     * @return Builder<BundleEntitlement>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $owned) use ($user): void {
            $owned->where('customer_user_id', $user->id);

            if ($user->email_verified_at !== null && $user->phone_verified_at !== null && $user->phone !== null) {
                $owned->orWhere(function (Builder $legacy) use ($user): void {
                    $legacy->whereNull('customer_user_id')
                        ->whereNull('customer_ownership_revoked_at')
                        ->whereRaw('LOWER(customer_email) = ?', [mb_strtolower($user->email)])
                        ->where('customer_phone', $user->phone)
                        ->whereNotNull('customer_email_verified_at')
                        ->whereNotNull('customer_phone_verified_at');
                });
            }
        });
    }

    /** @return BelongsTo<BundleDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(BundleDefinition::class, 'bundle_definition_id');
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
