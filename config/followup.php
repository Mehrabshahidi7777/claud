<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Escalation ladder
    |--------------------------------------------------------------------------
    |
    | Offsets are relative to the task deadline. Negative values run before it.
    | Every workspace may override these in `workspaces.settings`; the values
    | here are the defaults applied when it has not.
    |
    */

    'ladder' => [
        'nudge_hours_before' => 24,
        'due_morning_hour' => 8.5,
        'chase_hours_after' => 2,
        'escalate_hours_after_chase' => 4,
    ],

    /*
    | Critical tasks compress the ladder: the chase fires at the deadline itself
    | and the escalation 90 minutes later.
    */

    'critical' => [
        'chase_hours_after' => 0,
        'escalate_hours_after_chase' => 1.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quiet hours and working days
    |--------------------------------------------------------------------------
    |
    | Outside these windows an SMS is deferred to the next working moment — it
    | is never dropped. `week_days` uses Carbon's dayOfWeek (0 = Sunday).
    | The Iranian working week runs Saturday through Wednesday, with Thursday
    | a half day and Friday off.
    |
    */

    'working_hours' => [
        'start' => '08:00',
        'end' => '17:00',
        'thursday_end' => '13:00',
        'week_days' => [6, 0, 1, 2, 3, 4], // Sat, Sun, Mon, Tue, Wed, Thu
    ],

    'quiet_hours' => [
        'start' => '21:00',
        'end' => '08:00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttling
    |--------------------------------------------------------------------------
    |
    | A follow-up engine without restraint is a spam machine. These caps are
    | what keeps a customer from cancelling in month two.
    |
    */

    'caps' => [
        'per_user_per_day' => 3,
        'per_user_per_week' => 10,
        'min_minutes_between_messages' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    |
    | Minute-level precision buys nothing here and multiplies database load,
    | so the runner sweeps every five minutes and treats anything already due
    | as due now.
    |
    */

    'sweep_interval_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Weekly report
    |--------------------------------------------------------------------------
    |
    | Saturday at 08:00, read in the workspace's own timezone — the first
    | working hour of the Iranian week. A report that lands on Friday is read
    | on Sunday, by which point two more days have gone wrong.
    |
    | `day_of_week` follows Carbon: 0 is Sunday, 6 is Saturday.
    |
    */

    'report' => [
        'day_of_week' => 6,
        'hour' => 8,
    ],

    /*
    | How long a chase stays answerable. An inbound reply arriving after this
    | window is recorded but no longer changes the task.
    */

    'reply_window_hours' => 24,

    /*
    | A second deferral on one task stops being an excuse and becomes a signal,
    | so it is reported to the manager regardless of where the ladder stands.
    */

    'defer_count_before_escalation' => 2,

];
