<?php

namespace App\Console\Commands;

use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

class ExpireBookingHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Expire five-minute booking holds and release reserved credits safely.';

    public function handle(BookingService $bookings): int
    {
        $count = $bookings->expireHolds();
        $this->info("Expired {$count} booking hold(s).");

        return self::SUCCESS;
    }
}
