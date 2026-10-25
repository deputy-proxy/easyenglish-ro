<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $payment_id @property int $amount_ron @property int $credits_to_revoke @property string $status @property string $reason @property CarbonImmutable|null $confirmed_at */
class PaymentRefund extends Model
{
    protected $fillable = ['payment_id', 'provider_refund_id', 'amount_ron', 'currency', 'credits_to_revoke', 'free_credits_to_revoke', 'status', 'provider_status', 'reason', 'actor_id', 'idempotency_key', 'metadata', 'confirmed_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'confirmed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
