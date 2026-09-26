<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    |
    | "sep" is Saman Bank's electronic payment gateway. "fake" is what the
    | tests run against and what local development should stay on — a real
    | terminal moves real money.
    |
    */

    'gateway' => env('PAYMENT_GATEWAY', env('PAYMENT_DRIVER', 'fake')),

    /*
    |--------------------------------------------------------------------------
    | SEP (پرداخت الکترونیک سامان)
    |--------------------------------------------------------------------------
    |
    | A three step handshake: ask for a token against an amount, send the payer
    | to the gateway with it, then verify the reference the bank returns.
    |
    | The callback is never proof of payment. It arrives from the payer's own
    | browser and carries whatever that browser was given, so nothing is marked
    | paid until the verify call — made server to server — agrees on both the
    | reference and the amount.
    |
    */

    'sep' => [
        'terminal_id' => env('SEP_TERMINAL_ID'),

        'token_url' => env('SEP_TOKEN_URL', 'https://sep.shaparak.ir/onlinepg/onlinepg'),
        'payment_url' => env('SEP_PAYMENT_URL', 'https://sep.shaparak.ir/OnlinePG/OnlinePG'),
        'verify_url' => env('SEP_VERIFY_URL', 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction'),

        // A payer is sitting in front of the redirect waiting for it, so the
        // token request is kept short. Verification runs after they are back
        // and may take longer without anyone watching.
        'connect_timeout' => env('SEP_CONNECT_TIMEOUT', 5),
        'token_timeout' => env('SEP_TOKEN_TIMEOUT', 15),
        'verify_timeout' => env('SEP_VERIFY_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Everything is stored and transmitted in Rial, the unit the gateway works
    | in, and divided by ten only for display. Storing Toman is how a factor of
    | ten ends up on an invoice.
    |
    */

    'currency' => 'IRR',

    /*
    | Iranian VAT. On the invoice as its own line because the finance
    | department will not process one without it.
    */

    'vat_percent' => env('VAT_PERCENT', 10),

    /*
    |--------------------------------------------------------------------------
    | Grace period
    |--------------------------------------------------------------------------
    |
    | Recurring card payments do not exist for Iranian gateways: every renewal
    | is a person deciding to pay again. So the subscription does not end the
    | moment it expires — it is reminded, then given a week, then locked.
    |
    | Losing a paying customer to a forgotten renewal is the most avoidable
    | churn there is.
    |
    */

    'reminder_days_before' => [7, 3, 1],

    'grace_days' => env('SUBSCRIPTION_GRACE_DAYS', 7),

    /*
    | A trial that asks for a card is a trial most people never start. One
    | per phone number, on whichever of the three plans they sign up for: a
    | second trial means a second SIM card, which costs more than it saves.
    */

    'trial_days' => env('SUBSCRIPTION_TRIAL_DAYS', 15),

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Prices are per seat per month in Rial. The corporate plan carries the
    | business; the other two exist to build the funnel and the brand, and are
    | priced accordingly.
    |
    | A yearly term is charged for ten months — the two free months pull cash
    | forward and make a forgotten renewal twelve times less likely.
    |
    */

    'yearly_months_charged' => 10,

    'plans' => [

        'family' => [
            'name' => 'خانوادگی',
            'price_per_seat' => 990_000,
            'min_seats' => 1,
            'max_seats' => 6,
            'included_sms' => 100,
            'per_seat' => false,
        ],

        'friends' => [
            'name' => 'دوستانه',
            'price_per_seat' => 1_490_000,
            'min_seats' => 1,
            'max_seats' => 15,
            'included_sms' => 200,
            'per_seat' => false,
        ],

        'corporate' => [
            'name' => 'شرکتی',
            'price_per_seat' => 2_000_000,
            'min_seats' => 5,
            'max_seats' => 500,
            'included_sms' => 500,
            'per_seat' => true,
        ],

    ],

];
