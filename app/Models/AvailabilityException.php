<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $kind
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $reason
 * @property int|null $created_by
 */
class AvailabilityException extends Model
{
    protected static function booted(): void
    {
        static::saving(function (AvailabilityException $exception): void {
            if ($exception->starts_at >= $exception->ends_at) {
                throw ValidationException::withMessages(['ends_at' => 'The exception must end after it starts.']);
            }
            if (! in_array($exception->kind, ['availability_override', 'blocked'], true)) {
                throw ValidationException::withMessages(['kind' => 'Choose an availability override or a blocked period.']);
            }
        });
        static::creating(function (AvailabilityException $exception): void {
            $exception->created_by ??= auth()->id() === null ? null : (int) auth()->id();
        });
        static::created(fn (AvailabilityException $exception) => self::audit($exception, 'availability_exception.created'));
        static::updated(fn (AvailabilityException $exception) => self::audit($exception, 'availability_exception.updated'));
        static::deleted(fn (AvailabilityException $exception) => self::audit($exception, 'availability_exception.deleted'));
    }

    private static function audit(AvailabilityException $exception, string $action): void
    {
        BookingAudit::query()->create([
            'actor_id' => $exception->created_by ?? auth()->id(),
            'subject_type' => 'availability_exception',
            'subject_id' => $exception->getKey(),
            'action' => $action,
            'reason' => $exception->reason,
            'metadata' => $exception->only(['kind', 'starts_at', 'ends_at', 'reason']),
        ]);
    }

    protected $fillable = ['kind', 'starts_at', 'ends_at', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
