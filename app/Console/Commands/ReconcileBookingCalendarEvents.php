<?php

namespace App\Console\Commands;

use App\Jobs\Calendar\SyncBookingCalendarEvent;
use App\Models\Booking;
use Illuminate\Console\Command;

class ReconcileBookingCalendarEvents extends Command
{
    protected $signature = 'bookings:reconcile-calendar-events';

    protected $description = 'Queue missing, stale, or failed Google Calendar event writes for assigned bookings';

    public function handle(): int
    {
        $queued = 0;

        Booking::query()
            ->where(function ($query): void {
                $query->whereNotNull('teacher_id')->orWhereNotNull('google_calendar_user_id');
            })
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereIn('status', ['confirmed', 'refund_pending'])
                        ->where('starts_at', '>', now())
                        ->where(function ($query): void {
                            $query->whereNull('google_calendar_event_id')
                                ->orWhereNull('calendar_synced_at')
                                ->orWhereNotNull('calendar_sync_error')
                                ->orWhere(function ($query): void {
                                    $query->whereNotNull('rescheduled_at')->whereColumn('calendar_synced_at', '<', 'rescheduled_at');
                                })
                                ->orWhere(function ($query): void {
                                    $query->whereNotNull('teacher_assigned_at')->whereColumn('calendar_synced_at', '<', 'teacher_assigned_at');
                                });
                        });
                })->orWhere(function ($query): void {
                    $query->whereIn('status', ['cancelled', 'expired', 'failed', 'refunded'])
                        ->whereNotNull('confirmed_at')
                        ->where(function ($query): void {
                            $query->whereNull('calendar_synced_at')
                                ->orWhereNotNull('calendar_sync_error')
                                ->orWhere(function ($query): void {
                                    $query->whereNotNull('google_calendar_event_id')->whereNotNull('cancelled_at')->whereColumn('calendar_synced_at', '<', 'cancelled_at');
                                });
                        });
                });
            })
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$queued): void {
                foreach ($bookings as $booking) {
                    SyncBookingCalendarEvent::dispatch($booking->id);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} booking calendar-event reconciliation job(s).");

        return self::SUCCESS;
    }
}
