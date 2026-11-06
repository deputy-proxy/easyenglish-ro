<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewCallLink(User $user, Booking $booking): bool
    {
        if ($booking->customer_user_id === $user->id && $user->hasVerifiedEmail()) {
            return true;
        }

        if ($booking->teacher_id === $user->id && $user->isTeacher() && $user->hasVerifiedEmail()) {
            return true;
        }

        return $user->hasVerifiedEmail()
            && $booking->customer_user_id === null
            && $user->phone_verified_at !== null
            && $user->phone !== null
            && mb_strtolower(trim($user->email)) === mb_strtolower(trim($booking->customer_email))
            && $user->phone === $booking->customer_phone;
    }
}
