<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case Nudged = 'nudged';
    case Due = 'due';
    case Chased = 'chased';
    case Escalated = 'escalated';
    case Deferred = 'deferred';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * Closed tasks never receive another message, whatever the ladder says.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Done, self::Cancelled], true);
    }

    /**
     * A deferred task is waiting on its new deadline, not on the ladder, so it
     * is neither closed nor chaseable until it returns to open.
     */
    public function isChaseable(): bool
    {
        return ! $this->isClosed() && $this !== self::Deferred;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'باز',
            self::Nudged => 'یادآوری شده',
            self::Due => 'سررسید امروز',
            self::Chased => 'پیگیری شده',
            self::Escalated => 'گزارش به مدیر',
            self::Deferred => 'تأخیر پذیرفته‌شده',
            self::Done => 'انجام شد',
            self::Cancelled => 'لغو شد',
        };
    }
}
