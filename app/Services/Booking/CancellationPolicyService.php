<?php

namespace App\Services\Booking;

use App\Models\Booking;
use Carbon\CarbonImmutable;

class CancellationPolicyService
{
    public const RESTORE_CREDIT_AT_SECONDS = 86400;

    public const FREE_RESCHEDULE_AT_SECONDS = 43200;

    public function secondsUntilStart(Booking $booking, ?CarbonImmutable $now = null): int
    {
        return $booking->starts_at->getTimestamp() - ($now ?? CarbonImmutable::now('UTC'))->getTimestamp();
    }

    /** Returns the credit outcome for a customer cancellation: restore or forfeit. */
    public function customerCancellationOutcome(Booking $booking, ?CarbonImmutable $now = null): string
    {
        return $this->secondsUntilStart($booking, $now) >= self::RESTORE_CREDIT_AT_SECONDS
            ? 'restore'
            : 'forfeit';
    }

    /** @return array{can_cancel:bool,can_reschedule:bool,credit_outcome:string,seconds_until_start:int} */
    public function customerActions(Booking $booking, ?CarbonImmutable $now = null): array
    {
        $seconds = $this->secondsUntilStart($booking, $now);
        $confirmed = $booking->status === 'confirmed' && $seconds > 0;

        return [
            'can_cancel' => $confirmed,
            'can_reschedule' => $confirmed
                && $seconds >= self::FREE_RESCHEDULE_AT_SECONDS
                && $seconds < self::RESTORE_CREDIT_AT_SECONDS
                && $booking->reschedule_count < 1,
            'credit_outcome' => $seconds >= self::RESTORE_CREDIT_AT_SECONDS ? 'restore' : 'forfeit',
            'seconds_until_start' => $seconds,
        ];
    }
}
