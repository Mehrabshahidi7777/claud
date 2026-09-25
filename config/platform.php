<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform administrators
    |--------------------------------------------------------------------------
    |
    | The people who run پیگیر itself — not a customer's owner or admin, but
    | whoever sells it. They see every workspace, every payment and every
    | message, so the list lives in the environment rather than in a table a
    | bug could write to. Comma-separated mobile numbers in any common form
    | (09…, 989…, +989…). Empty means nobody, which is the safe default.
    |
    */

    'admin_phones' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLATFORM_ADMIN_PHONES', '')),
    ))),

];
