<?php

namespace App\Services\Booking;

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\TeacherCalendarBusyInterval;
use App\Models\User;
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
    public function slotsForDate(string $date, ?int $ignoreBookingId = null): Collection
    {
        $day = $this->parseDay($date);

        if ($day->lessThan(CarbonImmutable::now(self::TIMEZONE)->startOfDay())) {
            return collect();
        }

        if (! $this->teacherAvailabilityEnabled()) {
            return $this->legacySlotsForDate($day, $ignoreBookingId);
        }

        $slots = collect();
        foreach ($this->eligibleTeachers() as $teacher) {
            foreach ($this->slotsForTeacherDate($teacher, $day, $ignoreBookingId) as $slot) {
                $slots->put($slot['starts_at']->utc()->format('Y-m-d H:i:s'), $slot);
            }
        }

        return $slots->sortKeys()->values();
    }

    public function teacherAvailabilityEnabled(): bool
    {
        return (bool) config('easyenglish.teacher_availability_enabled', false);
    }

    /** @return Collection<int, User> */
    public function eligibleTeachersForSlot(CarbonImmutable $startsAt, ?int $ignoreBookingId = null): Collection
    {
        if (! $this->teacherAvailabilityEnabled()) {
            return collect();
        }

        $day = $startsAt->setTimezone(self::TIMEZONE)->startOfDay();

        return $this->eligibleTeachers()->filter(function (User $teacher) use ($startsAt, $day, $ignoreBookingId): bool {
            return $this->slotsForTeacherDate($teacher, $day, $ignoreBookingId)
                ->contains(fn (array $slot): bool => $slot['starts_at']->utc()->equalTo($startsAt->utc()));
        })->values();
    }

    public function assertBookable(CarbonImmutable $startsAt, ?int $ignoreBookingId = null): void
    {
        if ($this->teacherAvailabilityEnabled()) {
            if ($this->eligibleTeachersForSlot($startsAt, $ignoreBookingId)->isNotEmpty()) {
                return;
            }

            throw ValidationException::withMessages(['starts_at' => 'That time is no longer available. Please choose another slot.']);
        }

        $local = $startsAt->setTimezone(self::TIMEZONE);
        $slot = $this->slotsForDate($local->format('Y-m-d'), $ignoreBookingId)->first(fn (array $candidate): bool => $candidate['starts_at']->utc()->equalTo($startsAt->utc()));
        if ($slot === null) {
            throw ValidationException::withMessages(['starts_at' => 'That time is no longer available. Please choose another slot.']);
        }
    }

    /** @return Collection<int, User> */
    private function eligibleTeachers(): Collection
    {
        return User::query()
            ->where('is_teacher', true)
            ->where('teacher_active', true)
            ->whereHas('teacherCalendar', fn ($query) => $query->where('status', 'connected')->where('last_synced_at', '>=', now()->subMinutes(10)))
            ->with('teacherCalendar')
            ->withMax(['bookingsAsTeacher as last_assigned_at' => function ($query): void {
                $query->whereNotIn('status', ['cancelled', 'expired', 'failed', 'refunded'])
                    ->whereNotNull('teacher_assigned_at')
                    ->where(fn ($query) => $query->where('status', '!=', 'pending')->orWhere('hold_expires_at', '>', now()));
            }], 'teacher_assigned_at')
            ->orderBy('last_assigned_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}> */
    private function legacySlotsForDate(CarbonImmutable $day, ?int $ignoreBookingId): Collection
    {
        $weekday = $day->dayOfWeekIso % 7;
        $previousWeekday = ($weekday + 6) % 7;
        $windows = collect();

        AvailabilityRule::query()->whereNull('user_id')->where('is_active', true)->whereIn('weekday', [$weekday, $previousWeekday])->get()
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

                if ((int) $rule->weekday === $previousWeekday && $overnight) {
                    $windows->push([
                        'start' => $day->subDay()->setTimeFromTimeString($startTime),
                        'end' => $day->setTimeFromTimeString($endTime),
                    ]);
                }
            });

        $exceptions = AvailabilityException::query()->whereNull('user_id')
            ->where('starts_at', '<', $day->endOfDay()->utc()->toDateTimeString())
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
                if ($blocked || $this->conflictsWithBooking($start, $end, null, $ignoreBookingId)) {
                    continue;
                }
                $slots->put($start->utc()->format('Y-m-d H:i:s'), ['starts_at' => $start, 'ends_at' => $end]);
            }
        }

        return $slots->sortKeys()->values();
    }

    /** @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}> */
    private function slotsForTeacherDate(User $teacher, CarbonImmutable $day, ?int $ignoreBookingId): Collection
    {
        if (! $teacher->teacherCalendar?->isFreshFor($day)) {
            return collect();
        }

        $weekday = $day->dayOfWeekIso % 7;
        $previousWeekday = ($weekday + 6) % 7;
        $exceptions = $teacher->availabilityExceptions()
            ->where('starts_at', '<', $day->endOfDay()->utc()->toDateTimeString())
            ->where('ends_at', '>', $day->utc()->toDateTimeString())->get();
        $overrides = $exceptions->where('kind', 'availability_override');
        $windows = collect();

        if ($overrides->isNotEmpty()) {
            foreach ($overrides as $exception) {
                $start = CarbonImmutable::parse($exception->starts_at)->setTimezone(self::TIMEZONE);
                $end = CarbonImmutable::parse($exception->ends_at)->setTimezone(self::TIMEZONE);
                if ($start->lessThan($day->addDay()) && $end->greaterThan($day)) {
                    $windows->push(['start' => $start, 'end' => $end]);
                }
            }
        } else {
            $teacher->availabilityRules()->where('is_active', true)->whereIn('weekday', [$weekday, $previousWeekday])->get()
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

                    if ((int) $rule->weekday === $previousWeekday && $overnight) {
                        $windows->push([
                            'start' => $day->subDay()->setTimeFromTimeString($startTime),
                            'end' => $day->setTimeFromTimeString($endTime),
                        ]);
                    }
                });
        }

        $busyIntervals = TeacherCalendarBusyInterval::query()->where('user_id', $teacher->id)
            ->where('starts_at', '<', $day->endOfDay()->utc()->addMinutes(self::BUFFER_MINUTES)->toDateTimeString())
            ->where('ends_at', '>', $day->utc()->subMinutes(self::BUFFER_MINUTES)->toDateTimeString())
            ->get();

        $slots = collect();
        foreach ($windows as $window) {
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
                $calendarConflict = $busyIntervals->contains(function (TeacherCalendarBusyInterval $interval) use ($start, $end): bool {
                    $busyStart = CarbonImmutable::parse($interval->starts_at)->utc();
                    $busyEnd = CarbonImmutable::parse($interval->ends_at)->utc();
                    $occupiedStart = $start->utc()->subMinutes(self::BUFFER_MINUTES);
                    $occupiedEnd = $end->utc()->addMinutes(self::BUFFER_MINUTES);

                    return $occupiedStart->lessThan($busyEnd) && $occupiedEnd->greaterThan($busyStart);
                });

                if ($blocked || $calendarConflict || $this->conflictsWithBooking($start, $end, $teacher->id, $ignoreBookingId)) {
                    continue;
                }

                $slots->put($start->utc()->format('Y-m-d H:i:s'), ['starts_at' => $start, 'ends_at' => $end]);
            }
        }

        return $slots->sortKeys()->values();
    }

    private function conflictsWithBooking(CarbonImmutable $start, CarbonImmutable $end, ?int $teacherId, ?int $ignoreBookingId = null): bool
    {
        $bufferedStart = $start->utc()->subMinutes(self::BUFFER_MINUTES);
        $bufferedEnd = $end->utc()->addMinutes(self::BUFFER_MINUTES);

        return Booking::query()->where(function ($query): void {
            $query->whereIn('status', ['confirmed', 'refund_pending'])->orWhere(function ($query): void {
                $query->where('status', 'pending')->where('hold_expires_at', '>', now());
            });
        })->when($teacherId !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('teacher_id')->orWhere('teacher_id', $teacherId)))
            ->where('starts_at', '<', $bufferedEnd->toDateTimeString())
            ->where('ends_at', '>', $bufferedStart->toDateTimeString())
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->exists();
    }

    private function parseDay(string $date): CarbonImmutable
    {
        try {
            $day = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => 'Choose a valid date.']);
        }

        if ($day->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => 'Choose a valid date.']);
        }

        return $day;
    }
}
