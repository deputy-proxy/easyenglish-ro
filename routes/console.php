<?php

use App\Models\ContactVerificationChallenge;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('stripe:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('teachers:sync-calendars')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bookings:reconcile-calendar-events')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('verification:prune', function (): void {
    $deleted = ContactVerificationChallenge::query()->where('expires_at', '<', now()->subDay())->delete();
    $this->info("Pruned {$deleted} expired contact verification challenges.");
})->purpose('Delete expired contact verification challenges after the retention window');

Schedule::command('verification:prune')->daily()->withoutOverlapping();
