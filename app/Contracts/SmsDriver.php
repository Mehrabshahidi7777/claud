<?php

namespace App\Contracts;

use App\Sms\PatternMessage;
use App\Sms\SmsResult;

/**
 * The seam between the engine and whichever panel is sending. It takes a
 * pattern and its values rather than a body, because that is what a dedicated
 * Iranian line accepts — and because keeping the free-text shape out of the
 * contract stops anyone from writing code that only works in development.
 */
interface SmsDriver
{
    public function send(PatternMessage $message): SmsResult;

    /**
     * Remaining credit at the provider, or null when the driver has no way to
     * ask. This is the provider's balance, not the per-workspace quota.
     */
    public function credit(): ?int;
}
