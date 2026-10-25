<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $attempts
 * @property string $channel
 * @property string $event
 * @property string $status
 * @property string $idempotency_key
 * @property string|null $provider_message_id
 * @property string|null $provider_status
 * @property string|null $provider_error_code
 * @property string|null $last_error
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $sent_at
 * @property array<string, mixed>|null $metadata
 * @property Booking|null $booking
 * @property BundleEntitlement|null $entitlement
 * @property PaymentRefund|null $refund
 */
class NotificationDelivery extends Model
{
    protected $fillable = ['booking_id', 'bundle_entitlement_id', 'payment_refund_id', 'channel', 'event', 'status', 'attempts', 'next_attempt_at', 'sent_at', 'last_error', 'idempotency_key', 'metadata', 'provider_message_id', 'provider_status', 'provider_error_code'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'metadata' => 'array'];
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

    /** @return BelongsTo<PaymentRefund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(PaymentRefund::class, 'payment_refund_id');
    }
}
