<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Whether the workspace may still use the product. Grace counts: locking
     * someone out the hour their subscription lapsed loses a paying customer
     * over a forgotten renewal, which is the most avoidable churn there is.
     */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::Grace], true);
    }

    /**
     * Only a live subscription is worth reminding. A cancelled one was a
     * decision, not an oversight.
     */
    public function isRenewable(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::Grace], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'دوره آزمایشی',
            self::Active => 'فعال',
            self::Grace => 'مهلت ارفاق',
            self::Expired => 'منقضی',
            self::Cancelled => 'لغو شده',
        };
    }
}
