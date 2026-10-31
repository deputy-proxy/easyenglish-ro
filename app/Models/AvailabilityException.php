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
 * @property int|null $user_id
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
            if ($exception->user_id !== null && $exception->kind === 'availability_override') {
                $overlaps = self::query()->where('user_id', $exception->user_id)
                    ->where('kind', 'availability_override')
                    ->where('starts_at', '<', $exception->ends_at)
                    ->where('ends_at', '>', $exception->starts_at)
                    ->when($exception->exists, fn ($query) => $query->where('id', '!=', $exception->getKey()))
                    ->exists();

                if ($overlaps) {
                    throw ValidationException::withMessages(['starts_at' => 'Teacher availability overrides cannot overlap.']);
                }
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
            'metadata' => $exception->only(['user_id', 'kind', 'starts_at', 'ends_at', 'reason']),
        ]);
    }

    protected $fillable = ['user_id', 'kind', 'starts_at', 'ends_at', 'reason', 'created_by'];

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
