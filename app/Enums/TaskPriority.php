<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Low priority work is followed up in the app only. Sending an SMS for
     * everything is the fastest way to teach people to ignore the SMS.
     */
    public function warrantsSms(): bool
    {
        return $this !== self::Low;
    }

    /**
     * Critical tasks compress the ladder and may break quiet hours, but only
     * when the manager opted into that at creation time.
     */
    public function isCritical(): bool
    {
        return $this === self::Critical;
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'کم',
            self::Normal => 'متوسط',
            self::High => 'زیاد',
            self::Critical => 'بحرانی',
        };
    }
}
