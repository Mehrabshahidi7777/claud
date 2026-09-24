<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chasing unpaid invoices
    |--------------------------------------------------------------------------
    |
    | How many days past the due date before an outstanding receivable becomes
    | a task somebody is chased about. Not zero: an invoice a day late is
    | usually a payment already in flight, and chasing it costs goodwill with
    | the customer and credibility with the person being chased.
    |
    */

    'chase_after_days' => env('FINANCE_CHASE_AFTER_DAYS', 3),

    /*
    | How long the company has to file a receipt against an approved purchase
    | before the reconciliation view reports it as unaccounted for.
    */

    'reconcile_after_days' => env('FINANCE_RECONCILE_AFTER_DAYS', 14),

];
