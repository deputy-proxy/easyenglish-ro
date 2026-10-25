<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = ['booking_id', 'bundle_entitlement_id', 'channel', 'event', 'status', 'attempts', 'next_attempt_at', 'sent_at', 'last_error', 'idempotency_key'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<BundleEntitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BundleEntitlement::class, 'bundle_entitlement_id');
    }
}
