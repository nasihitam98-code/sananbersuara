<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Jadwal (butuh cron di server: * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
*/

Schedule::command('pemilihan:sapu-izin')->everyMinute()->withoutOverlapping();
