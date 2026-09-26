<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Workspace;

/**
 * How many people a workspace may have, and how many it has.
 *
 * The company pays for everyone; nobody pays for themselves. Every plan has a
 * ceiling (six in a household, fifteen among friends, five hundred in a
 * company). On the company plan, once it is paid for, the ceiling is also
 * the number of people paid for: adding the sixth person to a five-person
 * subscription means buying one more place first. A trial has no paid limit
 * so a company can try it with the whole team.
 */
class SeatLimit
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * People who count: everyone still active in the workspace.
     */
    public function used(Workspace $workspace): int
    {
        return $workspace->members()->wherePivotNull('deactivated_at')->count();
    }

    public function limit(Workspace $workspace): int
    {
        $planKey = $this->planKey($workspace);
        $planMax = (int) config("payment.plans.$planKey.max_seats", PHP_INT_MAX);

        $paid = $this->paidSubscription($workspace);

        if ($paid !== null && config("payment.plans.$planKey.per_seat")) {
            return min($paid->seats, $planMax);
        }

        return $planMax;
    }

    public function canAdd(Workspace $workspace): bool
    {
        return $this->used($workspace) < $this->limit($workspace);
    }

    /**
     * Whether more places can be bought right now: a paid company plan that
     * has not reached the plan's own ceiling.
     */
    public function canBuyMore(Workspace $workspace): bool
    {
        $planKey = $this->planKey($workspace);

        return $this->paidSubscription($workspace) !== null
            && (bool) config("payment.plans.$planKey.per_seat")
            && $this->limit($workspace) < (int) config("payment.plans.$planKey.max_seats");
    }

    /**
     * Why the next person cannot be added, in the owner's words.
     */
    public function refusal(Workspace $workspace): string
    {
        $limit = $this->limit($workspace);

        return $this->canBuyMore($workspace)
            ? "اشتراک شما برای {$limit} نفر است و ظرفیتش پر شده. از پایین همین صفحه ظرفیت را افزایش دهید."
            : "سقف این پلن {$limit} نفر است و پر شده.";
    }

    private function paidSubscription(Workspace $workspace): ?Subscription
    {
        $subscription = $this->billing->currentSubscription($workspace);

        return $subscription !== null && $subscription->status !== SubscriptionStatus::Trialing
            ? $subscription
            : null;
    }

    private function planKey(Workspace $workspace): string
    {
        return $this->billing->currentSubscription($workspace)?->plan_key ?? $workspace->type->planKey();
    }
}
