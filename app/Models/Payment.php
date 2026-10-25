<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $bundle_entitlement_id
 * @property int $amount_ron
 * @property int $refunded_amount_ron
 * @property string|null $checkout_url
 * @property CarbonImmutable|null $checkout_expires_at
 * @property string $currency
 * @property string $status
 * @property CarbonImmutable|null $confirmed_at
 * @property BundleEntitlement $entitlement
 */
class Payment extends Model
{
    protected $fillable = ['bundle_entitlement_id', 'provider', 'provider_checkout_id', 'checkout_request_key', 'checkout_url', 'checkout_expires_at', 'provider_payment_id', 'amount_ron', 'currency', 'status', 'confirmed_at', 'last_reconciled_at', 'refunded_amount_ron', 'last_error'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime', 'checkout_expires_at' => 'immutable_datetime', 'last_reconciled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BundleEntitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BundleEntitlement::class, 'bundle_entitlement_id');
    }

    /** @return HasMany<PaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}
