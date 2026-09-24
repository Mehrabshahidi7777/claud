<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum RecurrenceUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'روز',
            self::Week => 'هفته',
            self::Month => 'ماه',
            self::Year => 'سال',
        };
    }

    /**
     * Months and years are added as calendar steps rather than as a number of
     * days. "Every six months" from the 31st of a long month has to land on
     * the last day of a short one, not drift by three days every cycle — over
     * a few years that is the difference between an annual service and one
     * that slowly wanders into the wrong season.
     */
    public function advance(CarbonImmutable $from, int $count): CarbonImmutable
    {
        return match ($this) {
            self::Day => $from->addDays($count),
            self::Week => $from->addWeeks($count),
            self::Month => $from->addMonthsNoOverflow($count),
            self::Year => $from->addYearsNoOverflow($count),
        };
    }
}
