<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Jadwal (butuh cron di server: * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
*/

Schedule::command('pemilihan:sapu-izin')->everyMinute()->withoutOverlapping();
Schedule::command('pemilihan:retensi')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('pemilihan:backup')->everyFifteenMinutes()->withoutOverlapping();
