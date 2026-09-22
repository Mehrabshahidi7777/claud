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

        /*
        | Two models, because the two jobs want different things.
        |
        | `extraction` turns a manager's paragraph into structured tasks. It
        | needs to follow a JSON schema exactly and little else, so a small
        | model is both enough and fast.
        |
        | `writing` composes the weekly report's opening paragraph, which has
        | to read like a person wrote it. That is worth a larger model, and it
        | runs once a week per workspace rather than on every paste.
        |
        | Set both to the same name on a small VPS; nothing breaks.
        |
        | Pull whichever you choose before switching AI_PROVIDER to "ollama":
        |     ollama pull qwen2.5:7b-instruct
        |
        | Judge a candidate on twenty real Persian sentences from your own
        | customers before settling. A model that handles English cleanly can
        | still mangle Persian names, half-spaces and Jalali dates — and the
        | ones it mangles are exactly the ones this application feeds it.
        */
        'models' => [
            'extraction' => env('OLLAMA_MODEL_EXTRACTION', env('OLLAMA_MODEL', 'qwen2.5:7b-instruct')),
            'writing' => env('OLLAMA_MODEL_WRITING', env('OLLAMA_MODEL', 'qwen2.5:14b-instruct')),
        ],

        // A local model is slow. This is generous on purpose — every call runs
        // on a queue or a schedule, so nobody is watching a spinner.
        'timeout_seconds' => env('OLLAMA_TIMEOUT', 120),

        // Low but not zero: extraction wants consistency, not invention.
        'temperature' => env('OLLAMA_TEMPERATURE', 0.1),

        // Ollama unloads an idle model, and loading a 7B from disk costs
        // several seconds. Keeping it resident matters on the extraction path,
        // where someone is waiting.
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),
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
