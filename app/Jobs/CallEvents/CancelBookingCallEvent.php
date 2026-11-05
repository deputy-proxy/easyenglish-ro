<?php

namespace App\Jobs\CallEvents;

use App\Models\BookingCallEvent;
use App\Services\CallEvents\BookingCallEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CancelBookingCallEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public readonly int $eventId)
    {
        $this->afterCommit();
    }

    public function handle(BookingCallEventService $events): void
    {
        $events->cancel($this->eventId);
    }

    public function failed(Throwable $exception): void
    {
        BookingCallEvent::query()->whereKey($this->eventId)->whereIn('status', ['queued', 'cancelling'])
            ->update(['status' => 'cancel_pending', 'next_attempt_at' => now()->addMinute(), 'last_error_category' => 'queue_failure', 'last_error_message' => 'Call-event cancellation job ended unexpectedly.']);
    }
}
