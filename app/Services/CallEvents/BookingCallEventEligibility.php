<?php

namespace App\Services\CallEvents;

use App\Models\Booking;

class BookingCallEventEligibility
{
    public static function shouldCreate(Booking $booking, bool $applyToPaidSessions): bool
    {
        if ($booking->status !== 'confirmed' || $booking->teacher_id === null) {
            return false;
        }

        if ($booking->credit_type === 'paid') {
            return $applyToPaidSessions;
        }

        return ($booking->bundle_entitlement_id === null && $booking->credit_type === null)
            || ($booking->bundle_entitlement_id !== null && $booking->credit_type === 'free');
    }
}
