<?php

namespace App\Console\Commands;

use App\Contracts\SmsDriver;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Sms\PatternMessage;
use Illuminate\Console\Command;

class SweepSubscriptions extends Command
{
    protected $signature = 'subscriptions:sweep';

    protected $description = 'Remind expiring subscriptions, then move lapsed ones through grace to expired';

    /**
     * Recurring card payments do not exist for Iranian gateways, so every
     * renewal is a person choosing to pay again. Without this sweep a
     * perfectly happy customer lapses because nobody told them — which is the
     * most avoidable churn there is.
     */
    public function handle(SmsDriver $sms): int
    {
        // Free: nothing expires, and a "your subscription ends in three
        // days" text would be both wrong and a paid SMS.
        if (! config('payment.enabled')) {
            $this->info('Billing is off; nothing to sweep.');

            return self::SUCCESS;
        }

        $reminded = $this->remind($sms);
        $moved = $this->moveLapsed();

        $this->info("$reminded reminder(s) sent, $moved subscription(s) moved.");

        return self::SUCCESS;
    }

    private function remind(SmsDriver $sms): int
    {
        $days = (array) config('payment.reminder_days_before', [7, 3, 1]);
        $sent = 0;

        $subscriptions = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])
            ->whereBetween('ends_at', [now(), now()->addDays(max($days))])
            ->with('workspace.members')
            ->get();

        foreach ($subscriptions as $subscription) {
            $remaining = $subscription->daysUntilExpiry();

            // The tightest threshold that still covers the days remaining, and
            // has not been used yet. Ascending order matters: an account first
            // seen three days out gets the three-day warning only. Taking the
            // largest instead would fire the seven-day notice too, and the
            // owner would get two texts back to back.
            $threshold = collect($days)
                ->sort()
                ->first(fn (int $day) => $remaining <= $day && ! $subscription->hasBeenRemindedAt($day));

            if ($threshold === null) {
                continue;
            }

            foreach ($this->billingContacts($subscription) as $user) {
                if ($user->hasOptedOutOfSms()) {
                    continue;
                }

                $sms->send(PatternMessage::make($user->phone, 'subscription_expiring', [
                    'days' => (string) max($remaining, 0),
                    'plan' => $subscription->planName(),
                ]));

                $sent++;
            }

            // Every wider window is closed along with this one. An account
            // first seen three days out never gets the seven-day notice
            // afterwards — that window passed before we ever looked.
            foreach ($days as $day) {
                if ($day >= $threshold) {
                    $subscription->recordReminder($day);
                }
            }
        }

        return $sent;
    }

    /**
     * A lapsed subscription enters grace rather than ending, and only leaves
     * once the grace period itself runs out.
     */
    private function moveLapsed(): int
    {
        $moved = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])
            ->where('ends_at', '<', now())
            ->get()
            ->each(fn (Subscription $subscription) => $subscription->update([
                'status' => SubscriptionStatus::Grace,
                'grace_ends_at' => now()->addDays((int) config('payment.grace_days', 7)),
            ]))
            ->count();

        $moved += Subscription::query()
            ->where('status', SubscriptionStatus::Grace->value)
            ->where('grace_ends_at', '<', now())
            ->get()
            ->each(fn (Subscription $subscription) => $subscription->update([
                'status' => SubscriptionStatus::Expired,
            ]))
            ->count();

        return $moved;
    }

    /**
     * Owners and admins. A renewal notice is a billing matter, not something
     * to text the whole company.
     */
    private function billingContacts(Subscription $subscription)
    {
        return $subscription->workspace->members()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->get();
    }
}
