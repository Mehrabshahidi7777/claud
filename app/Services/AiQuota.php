<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One daily allowance of model calls per workspace, shared by every feature
 * that uses the model.
 *
 * The model runs on the same server as everything else, on the CPU. Without
 * a shared ceiling, one member pressing "استخراج دوباره" on a long meeting in
 * a loop could keep the processor busy for every other customer.
 */
class AiQuota
{
    public function take(Workspace $workspace): bool
    {
        $key = "ai:parse:workspace:{$workspace->id}";

        if (RateLimiter::tooManyAttempts($key, (int) config('ai.daily_parse_limit', 50))) {
            return false;
        }

        RateLimiter::hit($key, 86400);

        return true;
    }
}
