<?php

namespace Tests\Unit;

use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JalaliDateTest extends TestCase
{
    #[DataProvider('knownDates')]
    public function test_it_converts_jalali_to_gregorian(int $jy, int $jm, int $jd, string $expected): void
    {
        $this->assertSame($expected, JalaliDate::toGregorian($jy, $jm, $jd)?->toDateString());
    }

    #[DataProvider('knownDates')]
    public function test_it_converts_back_again(int $jy, int $jm, int $jd, string $gregorian): void
    {
        $this->assertSame(
            [$jy, $jm, $jd],
            JalaliDate::fromGregorian(CarbonImmutable::parse($gregorian)),
        );
    }

    /**
     * @return array<string, array{int, int, int, string}>
     */
    public static function knownDates(): array
    {
        return [
            'nowruz 1404' => [1404, 1, 1, '2025-03-21'],
            'mid year' => [1404, 7, 15, '2025-10-07'],
            'last day of a common year' => [1404, 12, 29, '2026-03-20'],
            'nowruz 1403' => [1403, 1, 1, '2024-03-20'],
            'leap year esfand 30' => [1403, 12, 30, '2025-03-20'],
        ];
    }

    public function test_it_rejects_an_impossible_date_rather_than_guessing(): void
    {
        // Esfand has 29 days in a common year, so the 30th does not exist.
        $this->assertNull(JalaliDate::toGregorian(1404, 12, 30));

        $this->assertNull(JalaliDate::toGregorian(1404, 13, 1));
        $this->assertNull(JalaliDate::toGregorian(1404, 7, 31));
        $this->assertNull(JalaliDate::toGregorian(1404, 0, 1));
    }

    public function test_it_knows_which_years_are_leap(): void
    {
        $this->assertTrue(JalaliDate::isLeap(1403));
        $this->assertFalse(JalaliDate::isLeap(1404));
    }

    public function test_it_formats_the_way_a_user_reads_a_date(): void
    {
        $this->assertSame('1404/07/15', JalaliDate::format(CarbonImmutable::parse('2025-10-07')));
    }
}
