<?php

use Illuminate\Support\Facades\Schedule;

/*
| The engine's heartbeat. A five minute sweep is deliberate: minute-level
| precision buys nothing a human would notice and multiplies database load,
| and anything already overdue is treated as due now.
|
| withoutOverlapping matters more than it looks. Two sweeps running together
| would each read the same pending rungs; the unique index on
| (task_id, step) stops a duplicate send, but not the wasted work.
*/

Schedule::command('followups:run')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
| The weekly report, checked hourly rather than sent weekly.
|
| "Saturday at 08:00" is a different instant for every workspace, and one cron
| expression can only be right for one timezone. So the command runs every
| hour and asks each workspace whether its own morning has arrived. The unique
| index on (workspace_id, period_start) makes asking twice harmless.
*/

Schedule::command('reports:weekly')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
