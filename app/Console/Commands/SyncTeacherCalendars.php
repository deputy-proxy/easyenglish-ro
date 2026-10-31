<?php

namespace App\Console\Commands;

use App\Jobs\Calendar\SyncTeacherCalendar;
use App\Models\TeacherCalendar;
use Illuminate\Console\Command;

class SyncTeacherCalendars extends Command
{
    protected $signature = 'teachers:sync-calendars';

    protected $description = 'Queue reconciliation of connected teacher Google Calendars and their busy intervals';

    public function handle(): int
    {
        $queued = 0;

        TeacherCalendar::query()->select('id')->orderBy('id')->chunkById(100, function ($calendars) use (&$queued): void {
            foreach ($calendars as $teacherCalendar) {
                SyncTeacherCalendar::dispatch($teacherCalendar->id);
                $queued++;
            }
        });

        $this->info("Queued {$queued} teacher calendar reconciliation job(s).");

        return self::SUCCESS;
    }
}
