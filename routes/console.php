<?php

use App\Console\Commands\CloseFinishedPlans;
use App\Console\Commands\RefreshFinancialAlerts;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled work.
|
| Alerts are rebuilt each morning so salary-day, bill and budget reminders are
| waiting when the user opens the app, and again in the evening to catch the
| day's spending. Finished cycles are closed just after midnight.
*/

Schedule::command(RefreshFinancialAlerts::class)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(RefreshFinancialAlerts::class)
    ->dailyAt('20:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(CloseFinishedPlans::class)
    ->dailyAt('00:15')
    ->withoutOverlapping()
    ->onOneServer();
