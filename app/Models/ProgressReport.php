<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressReport extends Model
{
    protected $fillable = [
        'booking_id', 'title', 'overall_level', 'summary', 'strengths', 'areas_to_improve',
        'recommendations', 'shared_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['shared_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
