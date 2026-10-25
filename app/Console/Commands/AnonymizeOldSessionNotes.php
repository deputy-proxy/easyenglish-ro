<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnonymizeOldSessionNotes extends Command
{
    protected $signature = 'bookings:anonymize-old-session-notes {--apply : Clear eligible session goals and notes (requires DATA_RETENTION_ENABLED=true)}';

    protected $description = 'Dry-run or anonymize session goals and notes older than the approved retention window';

    public function handle(): int
    {
        $cutoff = now()->subMonths(12);
        $query = Booking::query()
            ->where('starts_at', '<=', $cutoff->utc())
            ->where(function ($query): void {
                $query->whereNotNull('session_goal')->orWhereNotNull('notes');
            });

        $count = (clone $query)->count();

        if (! $this->option('apply')) {
            $this->info("Dry run: {$count} booking record(s) would have session goals/notes cleared (cutoff: {$cutoff->toIso8601String()}).");

            return self::SUCCESS;
        }

        if (! config('easyenglish.retention_enabled', false)) {
            $this->error('Retention writes are disabled. Obtain legal/accounting approval and set DATA_RETENTION_ENABLED=true before using --apply.');

            return self::FAILURE;
        }

        $updated = 0;
        DB::transaction(function () use ($query, &$updated): void {
            $query->orderBy('id')->chunkById(200, function ($bookings) use (&$updated): void {
                foreach ($bookings as $booking) {
                    $booking->forceFill(['session_goal' => '[anonymized]', 'notes' => null])->saveQuietly();
                    $updated++;
                }
            });
        });

        $this->info("Anonymized session goals and notes for {$updated} booking record(s). No financial or audit records were changed.");

        return self::SUCCESS;
    }
}
