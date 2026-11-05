<?php

namespace App\Services\Verification;

use App\Models\FreeSessionClaim;
use App\Support\PhoneNumber;

class FreeSessionEligibilityService
{
    /**
     * A person is ineligible when either normalized contact identifier has a
     * non-reusable claim. A teacher-cancelled claim without a booking may be
     * reused, matching BookingService's final transactional rule.
     *
     * @return array{status: 'eligible'|'already_used', eligible: bool}
     */
    public function check(string $email, string $phone): array
    {
        $email = mb_strtolower(trim($email));
        $phone = PhoneNumber::normalize($phone);

        $alreadyUsed = FreeSessionClaim::query()
            ->where(function ($query) use ($email, $phone): void {
                $query->whereRaw('LOWER(customer_email) = ?', [$email])
                    ->orWhere('customer_phone', $phone);
            })
            ->where(function ($query): void {
                $query->where('status', '!=', 'teacher_cancelled')
                    ->orWhereNotNull('booking_id');
            })
            ->exists();

        return [
            'status' => $alreadyUsed ? 'already_used' : 'eligible',
            'eligible' => ! $alreadyUsed,
        ];
    }
}
