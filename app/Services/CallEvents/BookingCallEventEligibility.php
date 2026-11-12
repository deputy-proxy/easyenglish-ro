<?php

namespace App\Services\CallEvents;

use App\Models\Booking;
use App\Models\User;

class BookingCallEventEligibility
{
    /** @param list<string> $rolloutCustomerEmails */
    public static function shouldCreate(Booking $booking, bool $applyToPaidSessions, string $rolloutStage, array $rolloutCustomerEmails = []): bool
    {
        if ($booking->status !== 'confirmed' || $booking->teacher_id === null) {
            return false;
        }

        // A free introduction always requires a call link for its booking emails.
        // Messaging rollout controls must not block call-event creation.
        if ($booking->bundle_entitlement_id === null && $booking->credit_type === null) {
            return true;
        }

        if ($rolloutStage === 'off') {
            return false;
        }

        $customerEmail = strtolower(trim((string) $booking->customer_email));
        $inRollout = match ($rolloutStage) {
            'internal_test', 'limited_customer' => in_array($customerEmail, $rolloutCustomerEmails, true),
            'staff_pilot' => User::query()->where('is_staff', true)->whereRaw('LOWER(email) = ?', [$customerEmail])->exists(),
            'general' => true,
            default => false,
        };
        if (! $inRollout) {
            return false;
        }

        if ($booking->credit_type === 'paid') {
            return $applyToPaidSessions;
        }

        return $booking->bundle_entitlement_id !== null && $booking->credit_type === 'free';
    }
}
