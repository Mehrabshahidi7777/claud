<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار تأیید',
            self::Approved => 'تأیید شد',
            self::Rejected => 'رد شد',
            self::Cancelled => 'لغو شد',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Tailwind classes for the status pill, kept beside the labels so a new
     * status cannot be added without deciding how it looks.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-100 text-amber-800',
            self::Approved => 'bg-emerald-100 text-emerald-800',
            self::Rejected => 'bg-red-100 text-red-800',
            self::Cancelled => 'bg-slate-100 text-slate-600',
        };
    }
}
