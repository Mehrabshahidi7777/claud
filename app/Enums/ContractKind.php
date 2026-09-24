<?php

namespace App\Enums;

enum ContractKind: string
{
    case Employment = 'employment';
    case Service = 'service';
    case Licence = 'licence';
    case Insurance = 'insurance';
    case Lease = 'lease';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Employment => 'قرارداد کارکنان',
            self::Service => 'قرارداد خدمات و پیمان',
            self::Licence => 'مجوز و گواهینامه',
            self::Insurance => 'بیمه‌نامه',
            self::Lease => 'اجاره‌نامه',
            self::Other => 'سایر',
        };
    }

    /**
     * How long before expiry the renewal should be raised, by default.
     *
     * Employment leads the list at two months because Iranian labour practice
     * treats an unnotified fixed-term contract as effectively continuing —
     * finding out on the day it lapsed is finding out too late. A licence is
     * given a month because reissuing one goes through an authority on its
     * own timetable, not the company's.
     */
    public function defaultNoticeDays(): int
    {
        return match ($this) {
            self::Employment => 60,
            self::Licence => 45,
            self::Insurance, self::Lease => 30,
            default => 30,
        };
    }

    /**
     * Whether letting this one lapse is a legal or commercial exposure rather
     * than an inconvenience. The page says so out loud, because "منقضی شده"
     * on a contractor qualification and on a stationery agreement are not the
     * same sentence.
     */
    public function lapseIsSerious(): bool
    {
        return in_array($this, [self::Employment, self::Licence, self::Insurance], true);
    }
}
