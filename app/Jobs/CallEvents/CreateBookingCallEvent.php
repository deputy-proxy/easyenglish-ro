<?php

namespace App\Jobs\CallEvents;

use App\Models\BookingCallEvent;
use App\Services\CallEvents\BookingCallEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateBookingCallEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public readonly int $bookingId, public readonly int $revision)
    {
        $this->afterCommit();
    }

    public function handle(BookingCallEventService $events): void
    {
        try {
            $events->createForBooking($this->bookingId, $this->revision);
        } catch (\DomainException $exception) {
            BookingCallEvent::query()->where('booking_id', $this->bookingId)->where('revision', $this->revision)
                ->whereIn('status', ['queued', 'creating'])
                ->update(['status' => 'failed', 'next_attempt_at' => null, 'last_error_category' => 'booking_not_eligible', 'last_error_message' => 'Booking was not confirmed and assigned when the call-event job ran.']);
            Log::notice('Booking call-event job skipped an ineligible booking.', ['booking_id' => $this->bookingId]);
        }
    }

    public function failed(Throwable $exception): void
    {
        BookingCallEvent::query()->where('booking_id', $this->bookingId)->where('revision', $this->revision)
            ->whereIn('status', ['queued', 'creating'])
            ->update(['status' => 'reconciliation_pending', 'next_attempt_at' => now()->addMinute(), 'last_error_category' => 'queue_failure', 'last_error_message' => 'Call-event creation job ended unexpectedly; reconciling with the same idempotency key.']);
    }
}
