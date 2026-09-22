<?php

namespace App\Sms\Drivers;

use App\Contracts\SmsDriver;
use App\Sms\PatternMessage;
use App\Sms\SmsResult;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;

/**
 * Writes what would have been sent to the log. This is the right default for
 * local work: the ladder runs end to end, the wording is visible, and no
 * credit is spent on a pattern that has not been approved yet.
 */
class LogSmsDriver implements SmsDriver
{
    public function __construct(private readonly LogManager $log) {}

    public function send(PatternMessage $message): SmsResult
    {
        $this->log->info('SMS (log driver)', [
            'to' => $message->phone,
            'pattern' => $message->key,
            'code' => $message->code,
            'tokens' => $message->tokens,
            'segments' => $message->segments(),
            'preview' => $message->preview,
        ]);

        return SmsResult::sent('log-'.Str::uuid()->toString());
    }

    public function credit(): ?int
    {
        return null;
    }
}
