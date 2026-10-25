<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $weekday
 * @property string $starts_at
 * @property string $ends_at
 * @property bool $is_active
 */
class AvailabilityRule extends Model
{
    protected static function booted(): void
    {
        static::saving(function (AvailabilityRule $rule): void {
            if ($rule->starts_at === $rule->ends_at) {
                throw ValidationException::withMessages(['ends_at' => 'The availability window must have different start and end times.']);
            }

            if (! $rule->is_active) {
                return;
            }

            $candidate = self::segmentsFor((int) $rule->weekday, (string) $rule->starts_at, (string) $rule->ends_at);
            $overlap = static::query()->where('is_active', true)->when($rule->exists, fn ($query) => $query->whereKeyNot($rule->getKey()))->get()
                ->contains(function (AvailabilityRule $existing) use ($candidate): bool {
                    foreach (self::segmentsFor((int) $existing->weekday, (string) $existing->starts_at, (string) $existing->ends_at) as [$existingStart, $existingEnd]) {
                        foreach ($candidate as [$candidateStart, $candidateEnd]) {
                            if ($candidateStart < $existingEnd && $candidateEnd > $existingStart) {
                                return true;
                            }
                        }
                    }

                    return false;
                });

            if ($overlap) {
                throw ValidationException::withMessages(['starts_at' => 'Active availability windows cannot overlap, including across midnight.']);
            }
        });

        static::created(fn (AvailabilityRule $rule) => self::audit($rule, 'availability_rule.created'));
        static::updated(fn (AvailabilityRule $rule) => self::audit($rule, 'availability_rule.updated'));
        static::deleted(fn (AvailabilityRule $rule) => self::audit($rule, 'availability_rule.deleted'));
    }

    /** @return array<int, array{int, int}> */
    private static function segmentsFor(int $weekday, string $start, string $end): array
    {
        $startMinute = ((int) substr($start, 0, 2) * 60) + (int) substr($start, 3, 2);
        $endMinute = ((int) substr($end, 0, 2) * 60) + (int) substr($end, 3, 2);
        $absoluteStart = ($weekday * 1440) + $startMinute;
        $absoluteEnd = ($weekday * 1440) + ($endMinute <= $startMinute ? $endMinute + 1440 : $endMinute);
        $weekMinutes = 7 * 1440;

        if ($absoluteEnd <= $weekMinutes) {
            return [[$absoluteStart, $absoluteEnd]];
        }

        return [[$absoluteStart, $weekMinutes], [0, $absoluteEnd - $weekMinutes]];
    }

    private static function audit(AvailabilityRule $rule, string $action): void
    {
        BookingAudit::query()->create([
            'actor_id' => auth()->id(),
            'subject_type' => 'availability_rule',
            'subject_id' => $rule->getKey(),
            'action' => $action,
            'metadata' => $rule->only(['weekday', 'starts_at', 'ends_at', 'is_active']),
        ]);
    }

    protected $fillable = ['weekday', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
