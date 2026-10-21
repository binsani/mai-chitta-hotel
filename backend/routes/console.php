<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| cPanel-Compatible Scheduled Tasks
|--------------------------------------------------------------------------
| Configure a single cPanel Cron Job running every minute:
| * * * * * /usr/local/bin/php /home/<cpanel_user>/mai-chitta/backend/artisan schedule:run >> /dev/null 2>&1
|
| No permanent supervisor/daemon process is required: queued jobs are
| drained in bounded 50-second bursts with overlap protection.
*/

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('auth:clear-resets')->hourly();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Expire stale booking holds every minute so abandoned online checkouts release inventory promptly.
Schedule::call(function () {
    app(\App\Services\AvailabilityService::class)->releaseExpiredHolds();
})->name('reservations:expire-holds')->everyMinute()->withoutOverlapping(2);
