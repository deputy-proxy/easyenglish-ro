<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $booking_id
 * @property int|null $teacher_id
 * @property string $provider
 * @property int $revision
 * @property string $idempotency_key
 * @property string|null $provider_event_id
 * @property string|null $join_url
 * @property string $call_type
 * @property CarbonImmutable $scheduled_start_at
 * @property CarbonImmutable $scheduled_end_at
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $provider_created_at
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property string|null $last_error_category
 * @property string|null $last_error_message
 * @property Booking|null $booking
 */
class BookingCallEvent extends Model
{
    protected $fillable = [
        'booking_id', 'teacher_id', 'provider', 'revision', 'idempotency_key', 'provider_event_id', 'join_url', 'call_type',
        'scheduled_start_at', 'scheduled_end_at', 'status', 'attempts', 'last_attempt_at', 'next_attempt_at',
        'provider_created_at', 'scheduled_at', 'cancelled_at', 'cancel_requested_at', 'last_error_category', 'last_error_message', 'admin_failure_alerted_at',
    ];

    protected $hidden = ['idempotency_key', 'provider_event_id', 'join_url'];

    protected function casts(): array
    {
        return [
            'join_url' => 'encrypted',
            'scheduled_start_at' => 'immutable_datetime',
            'scheduled_end_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'scheduled_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'admin_failure_alerted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
