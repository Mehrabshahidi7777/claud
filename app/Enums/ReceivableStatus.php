<?php

namespace App\Enums;

enum ReceivableStatus: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Settled = 'settled';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'وصول نشده',
            self::Partial => 'وصول جزئی',
            self::Settled => 'تسویه شد',
            self::WrittenOff => 'سوخت‌شده',
        };
    }

    /**
     * Whether money is still expected. A written-off receivable is history:
     * chasing it costs SMS credit and the manager's attention for a sum the
     * company has already decided it will not see.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Open, self::Partial], true);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-amber-100 text-amber-800',
            self::Partial => 'bg-sky-100 text-sky-800',
            self::Settled => 'bg-emerald-100 text-emerald-800',
            self::WrittenOff => 'bg-slate-100 text-slate-600',
        };
    }
}
