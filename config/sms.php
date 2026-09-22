<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    |
    | "fake" records messages in memory and is what the tests run against,
    | "log" writes them to the log channel for local development, and "amoot"
    | talks to the real panel. Local development should stay on "log" until a
    | pattern has actually been approved.
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    /*
    | The master kill switch. Flipping this to false stops every outbound
    | message across every workspace — the thing you reach for when a runaway
    | loop is burning credit.
    */

    'enabled' => env('SMS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | AmootSMS
    |--------------------------------------------------------------------------
    |
    | The dedicated line only accepts pattern-based sends, so every outbound
    | message maps to a pattern registered and approved in the panel. We never
    | send free text: we send a pattern code plus its values.
    |
    | VERIFY BEFORE FIRST REAL SEND: confirm the endpoint paths and the exact
    | parameter names against the API documentation in your own panel, then
    | adjust `endpoints` and `AmootSmsDriver::payloadFor()` to match. Every
    | other layer is insulated from this.
    |
    */

    'amoot' => [
        'base_url' => env('AMOOT_BASE_URL', 'https://portal.amootsms.com/rest'),
        'token' => env('AMOOT_TOKEN'),
        'line_number' => env('AMOOT_LINE_NUMBER'),
        'timeout_seconds' => env('AMOOT_TIMEOUT', 15),

        'endpoints' => [
            'send_pattern' => 'SendWithPattern',
            'credit' => 'CreditRemain',
        ],

        /*
        | Shared secret the inbound webhook must present. The route path is
        | unguessable on its own, but a compromised path should not be enough
        | to inject replies into anyone's tasks.
        */
        'inbound_secret' => env('AMOOT_INBOUND_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pattern registry
    |--------------------------------------------------------------------------
    |
    | Each key is a template the engine sends. `code` is the pattern id from
    | the panel and `tokens` names the variables that pattern expects, in the
    | order it expects them. `preview` is the approved wording, kept here so
    | the text stays reviewable in code — it is never what gets transmitted.
    |
    | A Persian SMS is encoded as UCS-2, which means 70 characters in a single
    | message and 67 per part once it splits. Every preview below is written to
    | fit one message at its longest realistic values.
    |
    */

    'patterns' => [

        'chase' => [
            'code' => env('AMOOT_PATTERN_CHASE'),
            'tokens' => ['name', 'title'],
            'preview' => "{name}، سررسید: {title}\n۱=انجام شد ۲=تأخیر\nهمین پیامک را پاسخ دهید",
        ],

        'defer_ask' => [
            'code' => env('AMOOT_PATTERN_DEFER_ASK'),
            'tokens' => [],
            'preview' => "تاریخ جدید را بفرستید\nمثال: 1404/07/15",
        ],

        'escalate' => [
            'code' => env('AMOOT_PATTERN_ESCALATE'),
            'tokens' => ['title', 'name', 'hours'],
            'preview' => "{title}\nمسئول: {name} - بدون پاسخ\nتأخیر: {hours} ساعت",
        ],

        'confirm_done' => [
            'code' => env('AMOOT_PATTERN_CONFIRM_DONE'),
            'tokens' => ['name'],
            'preview' => 'ثبت شد. ممنون {name}',
        ],

        'confirm_defer' => [
            'code' => env('AMOOT_PATTERN_CONFIRM_DEFER'),
            'tokens' => ['date'],
            'preview' => 'تاریخ جدید ثبت شد: {date}',
        ],

        'unknown' => [
            'code' => env('AMOOT_PATTERN_UNKNOWN'),
            'tokens' => [],
            'preview' => 'متوجه نشدم. ۱=انجام شد ۲=تأخیر',
        ],

        'otp' => [
            'code' => env('AMOOT_PATTERN_OTP'),
            'tokens' => ['code'],
            'preview' => "کد ورود: {code}\nتا ۲ دقیقه معتبر است",
        ],

        'welcome' => [
            'code' => env('AMOOT_PATTERN_WELCOME'),
            'tokens' => ['name', 'workspace'],
            'preview' => '{name} عزیز، به {workspace} اضافه شدید',
        ],

        /*
        | Every renewal is a person remembering to pay, so the reminder has to
        | reach them where they actually are.
        */
        'subscription_expiring' => [
            'code' => env('AMOOT_PATTERN_SUBSCRIPTION_EXPIRING'),
            'tokens' => ['days', 'plan'],
            'preview' => "اشتراک {plan} تا {days} روز دیگر تمام می‌شود\nبرای تمدید وارد پنل شوید",
        ],

        /*
        | A request waiting on someone. The title is truncated by the caller to
        | twenty characters, which is what keeps this inside one message at its
        | longest realistic values.
        */
        'approval_request' => [
            'code' => env('AMOOT_PATTERN_APPROVAL_REQUEST'),
            'tokens' => ['type', 'name', 'title'],
            'preview' => "درخواست {type} از {name}\n{title}\nمنتظر تأیید شماست",
        ],

        'approval_decision' => [
            'code' => env('AMOOT_PATTERN_APPROVAL_DECISION'),
            'tokens' => ['result', 'title'],
            'preview' => "درخواست شما {result} شد\n{title}",
        ],

        /*
        | The Saturday headline. Email deliverability to Iranian inboxes is
        | unreliable enough that a report living only in an inbox is one half
        | the customers never read, so the number itself travels by SMS.
        */
        'weekly_report' => [
            'code' => env('AMOOT_PATTERN_WEEKLY_REPORT'),
            'tokens' => ['rate', 'overdue'],
            'preview' => "گزارش هفته آماده است\nتکمیل به‌موقع: {rate}٪\nعقب‌افتاده: {overdue}",
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound keywords
    |--------------------------------------------------------------------------
    |
    | Matched after normalisation (Persian and Arabic digits folded to Latin,
    | Arabic letters folded to Persian, zero-width characters stripped), so
    | only the base forms need listing here.
    |
    */

    'keywords' => [
        'done' => ['1', 'انجام', 'انجام شد', 'اوکی', 'okay', 'ok', 'بله', 'تمام', 'شد'],
        'defer' => ['2', 'تاخیر', 'تأخیر', 'نشد', 'نمیرسم', 'نمی رسم', 'دیرتر'],
        'cancel' => ['لغو', 'حذف'],
        'opt_out' => ['قطع', 'لغو پیامک', 'خاموش', 'off'],
    ],

];
