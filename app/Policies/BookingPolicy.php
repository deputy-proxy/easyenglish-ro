<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewCallLink(User $user, Booking $booking): bool
    {
        if ($booking->teacher_id === $user->id && $user->isTeacher() && $user->hasVerifiedEmail()) {
            return true;
        }

        return $user->hasVerifiedEmail()
            && mb_strtolower(trim($user->email)) === mb_strtolower(trim($booking->customer_email));
    }
}
