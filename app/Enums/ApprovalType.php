<?php

namespace App\Enums;

enum ApprovalType: string
{
    case Leave = 'leave';
    case Purchase = 'purchase';
    case Expense = 'expense';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'مرخصی',
            self::Purchase => 'خرید',
            self::Expense => 'هزینه',
            self::Other => 'سایر',
        };
    }

    /**
     * Leave is the only type that changes what the follow-up engine does:
     * approving it tells the engine not to chase that person while they are
     * gone. The others are a record and a decision, nothing more.
     */
    public function needsDateRange(): bool
    {
        return $this === self::Leave;
    }

    public function needsAmount(): bool
    {
        return in_array($this, [self::Purchase, self::Expense], true);
    }
}
