<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Run the scheduler with "php artisan schedule:work" (development) or call
| "php artisan schedule:run" every minute from cron / Windows Task Scheduler.
*/

// Names, rarities and icons of new characters, weapons and sets.
Schedule::command('catalog:sync')
    ->dailyAt('04:00')
    ->timezone(config('hoyo.sync.timezone'))
    ->withoutOverlapping();

// Recommended builds from every enabled source. Sources synced successfully in the last
// hoyo.sync.stale_hours are skipped, so an extra manual run never hits a site twice a day.
Schedule::command('builds:sync')
    ->dailyAt(config('hoyo.sync.daily_at', '04:30'))
    ->timezone(config('hoyo.sync.timezone'))
    ->withoutOverlapping(180)
    ->runInBackground();

// Keep the HTTP cache from growing forever.
Schedule::call(function () {
    $disk = Storage::disk('local');
    foreach ($disk->files('http-cache') as $file) {
        if ($disk->lastModified($file) < now()->subDays(3)->getTimestamp()) {
            $disk->delete($file);
        }
    }
})->daily()->name('prune-http-cache');
