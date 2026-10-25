<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreeSessionClaim extends Model
{
    public function setCustomerPhoneAttribute(string $value): void
    {
        $this->attributes['customer_phone'] = PhoneNumber::normalize($value);
    }

    protected $fillable = ['customer_email', 'customer_phone', 'status', 'bundle_entitlement_id', 'booking_id', 'verified_at', 'used_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BundleEntitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BundleEntitlement::class, 'bundle_entitlement_id');
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
