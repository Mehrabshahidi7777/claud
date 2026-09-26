<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Workspace;
use Illuminate\Console\Command;

class ResetSmsAllowance extends Command
{
    protected $signature = 'sms:reset-allowance';

    protected $description = 'Start a new monthly SMS allowance for every workspace whose month has run out';

    /**
     * Every plan's SMS allowance is monthly, but a payment only resets it
     * when one arrives, and a yearly plan pays once a year. Without this a
     * yearly customer would spend a month's allowance and then have the
     * follow-up engine silenced for the other eleven.
     *
     * The quota itself is left alone: the platform owner may have raised it
     * by hand for this customer, and a new month is no reason to undo that.
     */
    public function handle(): int
    {
        $reset = 0;

        Workspace::query()
            ->whereHas('subscriptions', fn ($query) => $query->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Grace->value,
            ]))
            ->where(function ($query) {
                $cutoff = now()->subMonth();

                $query->where('sms_period_started_at', '<=', $cutoff)
                    ->orWhere(fn ($query) => $query->whereNull('sms_period_started_at')->where('created_at', '<=', $cutoff));
            })
            ->eachById(function (Workspace $workspace) use (&$reset) {
                $workspace->forceFill([
                    'sms_used' => 0,
                    'sms_period_started_at' => now(),
                ])->save();

                $reset++;
            });

        $this->info("$reset workspace(s) started a new SMS month.");

        return self::SUCCESS;
    }
}
