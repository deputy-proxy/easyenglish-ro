<?php

namespace App\Console\Commands;

use App\Jobs\CallEvents\CancelBookingCallEvent;
use App\Jobs\CallEvents\CreateBookingCallEvent;
use App\Jobs\CallEvents\RetrieveBookingCallEvent;
use App\Models\BookingCallEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchBookingCallEvents extends Command
{
    protected $signature = 'call-events:dispatch {--limit=100 : Maximum call-event operations to queue}';

    protected $description = 'Queue due OpenWA call-event creation, reconciliation, and cancellation operations';

    public function handle(): int
    {
        if (! (bool) config('services.openwa.enabled')) {
            $this->info('OpenWA call events are disabled.');

            return self::SUCCESS;
        }

        BookingCallEvent::query()->whereIn('status', ['creating', 'reconciling', 'cancelling'])
            ->where('updated_at', '<=', now()->subMinutes(20))
            ->get()->each(function (BookingCallEvent $event): void {
                $status = match ($event->status) {
                    'creating' => $event->provider_event_id !== null ? 'reconciliation_pending' : 'pending',
                    'reconciling' => 'reconciliation_pending',
                    'cancelling' => 'cancel_pending',
                    default => 'pending',
                };
                $event->update(['status' => $status, 'next_attempt_at' => now(), 'last_error_category' => 'stale_operation', 'last_error_message' => 'Operation lease expired and was returned to reconciliation.']);
            });

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $queued = 0;
        $failures = 0;
        BookingCallEvent::query()->whereIn('status', ['pending', 'reconciliation_pending', 'cancel_pending'])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })->orderBy('id')->limit($limit)->get()->each(function (BookingCallEvent $candidate) use (&$queued, &$failures): void {
                $claim = DB::transaction(function () use ($candidate): ?array {
                    $event = BookingCallEvent::query()->lockForUpdate()->find($candidate->id);
                    if (! $event || ! in_array($event->status, ['pending', 'reconciliation_pending', 'cancel_pending'], true)
                        || ($event->next_attempt_at !== null && $event->next_attempt_at->isFuture())) {
                        return null;
                    }
                    $originalStatus = $event->status;
                    $event->update(['status' => 'queued']);

                    return ['event' => $event->fresh(), 'original_status' => $originalStatus];
                }, attempts: 3);
                if ($claim === null) {
                    return;
                }

                /** @var BookingCallEvent $event */
                $event = $claim['event'];
                $job = match (true) {
                    $claim['original_status'] === 'cancel_pending' && $event->provider_event_id !== null => new CancelBookingCallEvent($event->id),
                    $claim['original_status'] === 'reconciliation_pending' && $event->provider_event_id !== null => new RetrieveBookingCallEvent($event->id),
                    default => new CreateBookingCallEvent($event->booking_id, $event->revision),
                };

                try {
                    dispatch($job->afterCommit());
                    $queued++;
                } catch (Throwable) {
                    $failures++;
                    BookingCallEvent::query()->whereKey($event->id)->where('status', 'queued')->update([
                        'status' => $claim['original_status'],
                        'next_attempt_at' => now()->addMinute(),
                        'last_error_category' => 'queue_dispatch_failed',
                        'last_error_message' => 'Call-event operation could not be queued.',
                    ]);
                }
            });

        $this->info("Queued {$queued} call-event operation(s); {$failures} queue dispatch failure(s).");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
