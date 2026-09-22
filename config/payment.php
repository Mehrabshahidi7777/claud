<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    |
    | Subscriptions are not part of the demo slice — the first customers are
    | sold and invoiced by hand. This file exists so the credentials have a
    | home and phase 2 is a matter of writing the driver, not rewiring config.
    |
    | SEP is Saman Bank's electronic payment gateway. Its flow is a two step
    | handshake: request a token for the amount, redirect the payer to the
    | gateway, then verify the returned reference against the terminal before
    | anything is marked paid. Never trust the callback alone.
    |
    */

    'driver' => env('PAYMENT_DRIVER', 'sep'),

    'sep' => [
        'terminal_id' => env('SEP_TERMINAL_ID'),
        'token_url' => env('SEP_TOKEN_URL', 'https://sep.shaparak.ir/onlinepg/onlinepg'),
        'payment_url' => env('SEP_PAYMENT_URL', 'https://sep.shaparak.ir/OnlinePG/OnlinePG'),
        'verify_url' => env('SEP_VERIFY_URL', 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction'),
        'callback_url' => env('SEP_CALLBACK_URL'),
        'timeout_seconds' => env('SEP_TIMEOUT', 20),
    ],

    /*
    | Amounts are stored in Rial, the unit the gateway itself works in, and
    | converted for display. Storing Toman here is how rounding bugs start.
    */

    'currency' => 'IRR',

];
