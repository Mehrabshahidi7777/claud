<?php

namespace App\Enums;

/**
 * The five rungs of the escalation ladder. The ordinal value is what the
 * unique index on (task_id, step) guards, so these numbers are load bearing:
 * changing one retires every scheduled follow-up that carries it.
 */
enum FollowUpStep: int
{
    case Nudge = 1;
    case DueMorning = 2;
    case Chase = 3;
    case Escalate = 4;
    case WeeklyReport = 5;

    public function channel(): FollowUpChannel
    {
        return match ($this) {
            self::Nudge, self::DueMorning => FollowUpChannel::Notification,
            self::Chase, self::Escalate => FollowUpChannel::Sms,
            self::WeeklyReport => FollowUpChannel::Email,
        };
    }

    /**
     * The chase goes to whoever owns the task; the escalation goes exactly one
     * rung up, to their direct manager — never to the chief executive, who
     * only ever sees the weekly roll-up.
     */
    public function goesToManager(): bool
    {
        return $this === self::Escalate;
    }

    public function patternKey(): ?string
    {
        return match ($this) {
            self::Chase => 'chase',
            self::Escalate => 'escalate',
            default => null,
        };
    }

    /**
     * The ladder stops here. Repeating an SMS past the escalation annoys the
     * recipient and runs up the bill for nothing.
     */
    public function isFinalSmsStep(): bool
    {
        return $this === self::Escalate;
    }

    public function label(): string
    {
        return match ($this) {
            self::Nudge => 'یادآوری آرام',
            self::DueMorning => 'یادآوری صبح سررسید',
            self::Chase => 'پیامک پیگیری',
            self::Escalate => 'تشدید به مدیر',
            self::WeeklyReport => 'ثبت در گزارش هفتگی',
        };
    }
}
