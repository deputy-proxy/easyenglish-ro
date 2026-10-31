<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $calendar_id
 * @property string|null $calendar_email
 * @property string $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property string $status
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_sync_error
 * @property User $user
 */
class TeacherCalendar extends Model
{
    protected $fillable = [
        'user_id', 'calendar_id', 'calendar_email', 'access_token', 'refresh_token', 'token_expires_at',
        'status', 'last_synced_at', 'last_sync_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TeacherCalendarBusyInterval, $this> */
    public function busyIntervals(): HasMany
    {
        return $this->hasMany(TeacherCalendarBusyInterval::class, 'user_id', 'user_id');
    }

    public function isFresh(): bool
    {
        return $this->status === 'connected'
            && $this->last_synced_at !== null
            && $this->last_synced_at->greaterThanOrEqualTo(now()->subMinutes(10));
    }

    public function isFreshFor(CarbonImmutable $day): bool
    {
        return $this->isFresh()
            && $day->lessThanOrEqualTo(CarbonImmutable::now('Europe/Bucharest')->addYears(2)->endOfDay());
    }
}
