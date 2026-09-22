<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | "ollama" runs a model on your own server, which is the whole selling
    | point: a bank or a state contractor can be told that nothing leaves
    | their machine. "null" disables the feature and leaves the manual form,
    | which is what the product falls back to whenever the model is unreachable
    | or answers with something that will not parse.
    |
    */

    'provider' => env('AI_PROVIDER', 'null'),

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),

        // Verify Persian quality against twenty real sentences before settling
        // on a model. A model that handles English well may still mangle
        // Persian names and dates.
        'model' => env('OLLAMA_MODEL', 'qwen2.5:7b'),

        // A local model is slow. This is generous on purpose — the call runs on
        // a queue, so nobody is watching a spinner.
        'timeout_seconds' => env('OLLAMA_TIMEOUT', 120),

        // Low but not zero: extraction wants consistency, not invention.
        'temperature' => env('OLLAMA_TEMPERATURE', 0.1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget
    |--------------------------------------------------------------------------
    |
    | A per-workspace ceiling on parses per day. This is the lever that turns
    | into an upgrade prompt once a customer leans on the feature.
    |
    */

    'daily_parse_limit' => env('AI_DAILY_PARSE_LIMIT', 50),

];
