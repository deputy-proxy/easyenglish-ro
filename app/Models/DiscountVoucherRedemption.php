<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $discount_voucher_id
 * @property int $bundle_entitlement_id
 * @property string $status
 * @property CarbonImmutable|null $redeemed_at
 */
class DiscountVoucherRedemption extends Model
{
    protected $fillable = ['discount_voucher_id', 'bundle_entitlement_id', 'status', 'redeemed_at'];

    protected function casts(): array
    {
        return ['redeemed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DiscountVoucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(DiscountVoucher::class, 'discount_voucher_id');
    }

    /** @return BelongsTo<BundleEntitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BundleEntitlement::class, 'bundle_entitlement_id');
    }
}
