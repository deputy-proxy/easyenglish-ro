<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $payment_id
 * @property string $provider_dispute_id
 * @property string|null $provider_payment_intent_id
 * @property string|null $provider_charge_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string|null $reason
 * @property CarbonImmutable|null $evidence_due_at
 * @property CarbonImmutable|null $created_at_provider
 * @property CarbonImmutable|null $closed_at_provider
 */
class PaymentDispute extends Model
{
    protected $fillable = [
        'payment_id',
        'provider_dispute_id',
        'provider_payment_intent_id',
        'provider_charge_id',
        'amount_minor',
        'currency',
        'status',
        'reason',
        'evidence_due_at',
        'created_at_provider',
        'closed_at_provider',
        'last_event_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'evidence_due_at' => 'immutable_datetime',
            'created_at_provider' => 'immutable_datetime',
            'closed_at_provider' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
