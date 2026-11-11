<?php

namespace App\Services\Booking;

use App\Models\User;
use App\Services\Verification\FreeSessionEligibilityService;

class PublicBookingStateResolver
{
    /** @return array{state: string, display_name: string|null, show_calendar: bool, booking_enabled: bool, show_bundles_message: bool, eligibility: array<string, mixed>|null} */
    public function resolve(?User $user, FreeSessionEligibilityService $eligibility, ?string $phone = null): array
    {
        if ($user === null) {
            return $this->makeState('visitor', null, true, true, false);
        }

        if ($user->is_staff || $user->is_admin || $user->is_teacher || ! $user->studentProfile()->exists()) {
            return $this->makeState('internal_user', $this->displayName($user), true, false, false);
        }

        $freeSession = $eligibility->check((string) $user->email, $phone ?? (string) $user->phone);

        if (! $freeSession['eligible']) {
            return $this->makeState('customer_free_session_used', $this->displayName($user), false, false, true, $freeSession);
        }

        return $this->makeState('customer_free_session_available', $this->displayName($user), true, true, false, $freeSession);
    }

    public function canUsePublicBooking(User $user): bool
    {
        return ! $user->is_staff && ! $user->is_admin && ! $user->is_teacher && $user->studentProfile()->exists();
    }

    /**
     * @param  array<string, mixed>|null  $eligibility
     * @return array{state: string, display_name: string|null, show_calendar: bool, booking_enabled: bool, show_bundles_message: bool, eligibility: array<string, mixed>|null}
     */
    private function makeState(string $state, ?string $name, bool $showCalendar, bool $bookingEnabled, bool $showBundlesMessage, ?array $eligibility = null): array
    {
        return ['state' => $state, 'display_name' => $name, 'show_calendar' => $showCalendar, 'booking_enabled' => $bookingEnabled, 'show_bundles_message' => $showBundlesMessage, 'eligibility' => $eligibility];
    }

    private function displayName(User $user): ?string
    {
        $name = trim((string) $user->name);
        $firstName = preg_split('/\s+/u', $name)[0] ?? '';

        return $firstName !== '' ? $firstName : null;
    }
}
