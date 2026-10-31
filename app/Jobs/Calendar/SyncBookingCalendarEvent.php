<?php

namespace App\Jobs\Calendar;

use App\Models\Booking;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncBookingCalendarEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $uniqueFor = 7200;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $bookingId) {}

    public function uniqueId(): string
    {
        return (string) $this->bookingId;
    }

    public function handle(GoogleCalendarService $calendar): void
    {
        $booking = Booking::query()->findOrFail($this->bookingId);
        $calendar->synchronizeBooking($booking);
    }

    public function failed(Throwable $exception): void
    {
        Booking::query()->whereKey($this->bookingId)->update([
            'calendar_sync_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
