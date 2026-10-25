<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bundle_entitlement_id
 * @property int $amount_ron
 * @property string $currency
 * @property string $status
 * @property CarbonImmutable|null $confirmed_at
 * @property BundleEntitlement $entitlement
 */
class Payment extends Model
{
    protected $fillable = ['bundle_entitlement_id', 'provider', 'provider_checkout_id', 'provider_payment_id', 'amount_ron', 'currency', 'status', 'confirmed_at'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BundleEntitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BundleEntitlement::class, 'bundle_entitlement_id');
    }
}
