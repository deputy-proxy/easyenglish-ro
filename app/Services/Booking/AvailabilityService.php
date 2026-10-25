<?php

namespace App\Services\Booking;

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AvailabilityService
{
    public const TIMEZONE = 'Europe/Bucharest';

    public const SESSION_MINUTES = 15;

    public const BUFFER_MINUTES = 15;

    public const SLOT_OCCUPANCY_MINUTES = self::SESSION_MINUTES + self::BUFFER_MINUTES;

    /** @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}> */
    public function slotsForDate(string $date): Collection
    {
        try {
            $day = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => 'Choose a valid date.']);
        }

        if ($day->format('Y-m-d') !== $date || $day->lessThan(CarbonImmutable::now(self::TIMEZONE)->startOfDay())) {
            return collect();
        }

        $weekday = $day->dayOfWeekIso % 7;
        $previousWeekday = ($weekday + 6) % 7;
        $windows = collect();

        AvailabilityRule::query()->where('is_active', true)->whereIn('weekday', [$weekday, $previousWeekday])->get()
            ->each(function (AvailabilityRule $rule) use ($day, $weekday, $previousWeekday, $windows): void {
                $startTime = (string) $rule->starts_at;
                $endTime = (string) $rule->ends_at;
                $overnight = $endTime <= $startTime;

                if ((int) $rule->weekday === $weekday) {
                    $start = $day->setTimeFromTimeString($startTime);
                    $end = $day->setTimeFromTimeString($endTime);
                    if ($overnight) {
                        $end = $end->addDay();
                    }
                    $windows->push(['start' => $start, 'end' => $end]);

                    return;
                }

                // Include the continuation of the previous weekday's overnight rule.
                if ((int) $rule->weekday === $previousWeekday && $overnight) {
                    $windows->push([
                        'start' => $day->subDay()->setTimeFromTimeString($startTime),
                        'end' => $day->setTimeFromTimeString($endTime),
                    ]);
                }
            });

        $exceptions = AvailabilityException::query()->where('starts_at', '<', $day->endOfDay()->utc()->toDateTimeString())
            ->where('ends_at', '>', $day->utc()->toDateTimeString())->get();

        foreach ($exceptions->where('kind', 'availability_override') as $exception) {
            $start = CarbonImmutable::parse($exception->starts_at)->setTimezone(self::TIMEZONE);
            $end = CarbonImmutable::parse($exception->ends_at)->setTimezone(self::TIMEZONE);
            if ($start->lessThan($day->addDay()) && $end->greaterThan($day)) {
                $windows->push(['start' => $start, 'end' => $end]);
            }
        }

        $slots = collect();
        foreach ($windows as $window) {
            // The full session plus its buffer must fit inside an availability window.
            for ($start = $window['start']; $start->addMinutes(self::SLOT_OCCUPANCY_MINUTES)->lessThanOrEqualTo($window['end']); $start = $start->addMinutes(self::SLOT_OCCUPANCY_MINUTES)) {
                $end = $start->addMinutes(self::SESSION_MINUTES);
                if ($start->format('Y-m-d') !== $day->format('Y-m-d') || $start->lessThanOrEqualTo(CarbonImmutable::now(self::TIMEZONE))) {
                    continue;
                }
                $blocked = $exceptions->where('kind', 'blocked')->contains(function (AvailabilityException $exception) use ($start, $end): bool {
                    $blockStart = CarbonImmutable::parse($exception->starts_at)->setTimezone(self::TIMEZONE);
                    $blockEnd = CarbonImmutable::parse($exception->ends_at)->setTimezone(self::TIMEZONE);

                    return $start->lessThan($blockEnd) && $end->greaterThan($blockStart);
                });
                if ($blocked || $this->conflictsWithBooking($start, $end)) {
                    continue;
                }
                $key = $start->utc()->format('Y-m-d H:i:s');
                $slots->put($key, ['starts_at' => $start, 'ends_at' => $end]);
            }
        }

        return $slots->sortKeys()->values();
    }

    public function assertBookable(CarbonImmutable $startsAt): void
    {
        $local = $startsAt->setTimezone(self::TIMEZONE);
        $slot = $this->slotsForDate($local->format('Y-m-d'))->first(fn (array $candidate): bool => $candidate['starts_at']->utc()->equalTo($startsAt->utc()));
        if ($slot === null) {
            throw ValidationException::withMessages(['starts_at' => 'That time is no longer available. Please choose another slot.']);
        }
    }

    private function conflictsWithBooking(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $bufferedStart = $start->utc()->subMinutes(self::BUFFER_MINUTES);
        $bufferedEnd = $end->utc()->addMinutes(self::BUFFER_MINUTES);

        return Booking::query()->where(function ($query): void {
            $query->where('status', 'confirmed')->orWhere(function ($query): void {
                $query->where('status', 'pending')->where('hold_expires_at', '>', now());
            });
        })->where('starts_at', '<', $bufferedEnd->toDateTimeString())
            ->where('ends_at', '>', $bufferedStart->toDateTimeString())->exists();
    }
}
