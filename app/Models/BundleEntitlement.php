<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $customer_email
 * @property string $customer_phone
 * @property CarbonImmutable|null $customer_email_verified_at
 * @property CarbonImmutable|null $customer_phone_verified_at
 * @property int $bundle_definition_id
 * @property string $status
 * @property string $locale
 * @property int $paid_amount_ron
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

    protected $fillable = ['bundle_definition_id', 'checkout_request_key', 'customer_name', 'customer_email', 'customer_phone', 'locale', 'customer_email_verified_at', 'customer_phone_verified_at', 'status', 'paid_amount_ron', 'currency', 'paid_credits', 'free_credits_granted', 'credits_remaining', 'free_credits_remaining', 'activated_at', 'expires_at'];

    protected function casts(): array
    {
        return ['customer_email_verified_at' => 'immutable_datetime', 'customer_phone_verified_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
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
