<?php

namespace App\Jobs\Calendar;

use App\Models\TeacherCalendar;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncTeacherCalendar implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $uniqueFor = 7200;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $teacherCalendarId) {}

    public function uniqueId(): string
    {
        return (string) $this->teacherCalendarId;
    }

    public function handle(GoogleCalendarService $calendar): void
    {
        $teacherCalendar = TeacherCalendar::query()->findOrFail($this->teacherCalendarId);
        $calendar->sync($teacherCalendar);
    }

    public function failed(Throwable $exception): void
    {
        TeacherCalendar::query()->whereKey($this->teacherCalendarId)->update([
            'status' => 'error',
            'last_sync_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
