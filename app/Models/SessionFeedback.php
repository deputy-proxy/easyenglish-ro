<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $booking_id
 * @property int|null $recorded_by
 * @property string $metric_key
 * @property int|null $baseline_score
 * @property int|null $current_score
 * @property int|null $potential_score
 * @property string|null $feedback
 * @property Booking $booking
 */
class SessionFeedback extends Model
{
    protected $table = 'session_feedback';

    protected $fillable = [
        'booking_id', 'recorded_by', 'metric_key', 'baseline_score', 'current_score', 'potential_score', 'feedback',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'baseline_score' => 'integer',
            'current_score' => 'integer',
            'potential_score' => 'integer',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
