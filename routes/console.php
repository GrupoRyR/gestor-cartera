<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Base schedule
|--------------------------------------------------------------------------
|
| cPanel runs "php artisan schedule:run" every minute (shared hosting has no
| supervisor), so the queue is drained here. Module tasks are registered by
| each module's service provider through its schedule() hook, never in this
| file. Timezone: America/Bogota (config/app.php).
|
*/

// The worker exits within 50 seconds (--max-time), so its lock expires after
// 5 minutes instead of the default 24 hours: a worker killed by the host
// (memory, timeout) stops the queue for minutes, not a whole day.
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('queue:prune-failed --hours=720')->daily();
