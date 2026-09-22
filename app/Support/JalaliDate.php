<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Jalali ↔ Gregorian conversion.
 *
 * The database stores Gregorian and every calculation runs on it; Jalali
 * exists only where a person reads or writes a date — a form, a report, the
 * date someone texts back. Letting the Jalali form reach the database is how
 * date arithmetic quietly breaks.
 *
 * The algorithm is the standard one by Roozbeh Pournader and Mohammad Toossi,
 * valid across the range any business calendar will ever need.
 */
final class JalaliDate
{
    /** Days elapsed at the start of each Gregorian month in a common year. */
    private const GREGORIAN_MONTH_OFFSETS = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    public static function toGregorian(int $jy, int $jm, int $jd): ?CarbonImmutable
    {
        if (! self::isValidJalali($jy, $jm, $jd)) {
            return null;
        }

        $jy += 1595;
        $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;
        $monthLengths = [31, self::isGregorianLeap($gy) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 0;
        while ($gm < 12 && $gd > $monthLengths[$gm]) {
            $gd -= $monthLengths[$gm];
            $gm++;
        }

        return CarbonImmutable::create($gy, $gm + 1, $gd, 0, 0, 0);
    }

    /**
     * @return array{0: int, 1: int, 2: int} year, month, day
     */
    public static function fromGregorian(CarbonImmutable $date): array
    {
        $gy = (int) $date->year;
        $gm = (int) $date->month;
        $gd = (int) $date->day;

        $shifted = $gm > 2 ? $gy + 1 : $gy;

        $days = 355666 + (365 * $gy) + intdiv($shifted + 3, 4) - intdiv($shifted + 99, 100)
            + intdiv($shifted + 399, 400) + $gd + self::GREGORIAN_MONTH_OFFSETS[$gm - 1];

        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + ($days % 31)];
        }

        return [$jy, 7 + intdiv($days - 186, 30), 1 + (($days - 186) % 30)];
    }

    /**
     * The form a Persian-speaking user reads: 1404/07/15.
     */
    public static function format(CarbonImmutable $date): string
    {
        [$year, $month, $day] = self::fromGregorian($date);

        return sprintf('%04d/%02d/%02d', $year, $month, $day);
    }

    public static function isLeap(int $jy): bool
    {
        return ((($jy + 2346) % 2820) % 128) % 4 === 1;
    }

    private static function isGregorianLeap(int $gy): bool
    {
        return ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
    }

    private static function isValidJalali(int $jy, int $jm, int $jd): bool
    {
        if ($jy < 1 || $jm < 1 || $jm > 12 || $jd < 1) {
            return false;
        }

        // The first six months hold 31 days, the next five 30, and Esfand 29
        // except in a leap year.
        $maxDay = match (true) {
            $jm <= 6 => 31,
            $jm <= 11 => 30,
            default => self::isLeap($jy) ? 30 : 29,
        };

        return $jd <= $maxDay;
    }
}
