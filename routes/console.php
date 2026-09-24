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

/*
| Subscriptions, swept daily. Iranian gateways have no recurring card payment,
| so a renewal is always a person choosing to pay again — and a customer who
| lapses because nobody reminded them is the most avoidable churn there is.
*/

Schedule::command('subscriptions:sweep')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
| Overdue receivables, once a day at the start of the working morning.
|
| Daily rather than hourly because nothing about an unpaid invoice changes
| between 9:05 and 9:10, and because the chase task it raises enters the
| ordinary ladder — running this often would only bring the task forward by
| minutes while multiplying the chance of a duplicate.
*/

Schedule::command('receivables:chase')
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
| Recurring work, raised once a day.
|
| Early enough that a service falling due today is on somebody's list before
| they have planned their morning, and daily rather than hourly because a
| lead time measured in days gains nothing from being checked in minutes.
*/

Schedule::command('recurrences:run')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->runInBackground();
