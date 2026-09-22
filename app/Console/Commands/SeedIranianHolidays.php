<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use App\Support\JalaliDate;
use Illuminate\Console\Command;

class SeedIranianHolidays extends Command
{
    protected $signature = 'holidays:seed {year? : Jalali year, defaults to the current one}';

    protected $description = 'Seed the fixed solar-calendar public holidays for a Jalali year';

    /**
     * Only the holidays that fall on fixed solar dates.
     *
     * The religious ones follow the lunar calendar and move about eleven days
     * against this one every year, so they cannot be derived — they are added
     * by hand each year with `holidays:add`, or the engine will text people on
     * Ashura.
     *
     * @var array<string, string> month/day => title
     */
    private const FIXED = [
        '01/01' => 'نوروز',
        '01/02' => 'نوروز',
        '01/03' => 'نوروز',
        '01/04' => 'نوروز',
        '01/12' => 'روز جمهوری اسلامی',
        '01/13' => 'روز طبیعت',
        '03/14' => 'رحلت امام خمینی',
        '03/15' => 'قیام ۱۵ خرداد',
        '11/22' => 'پیروزی انقلاب اسلامی',
        '12/29' => 'روز ملی شدن صنعت نفت',
    ];

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?: JalaliDate::fromGregorian(now()->toImmutable())[0]);

        $added = 0;

        foreach (self::FIXED as $monthDay => $title) {
            [$month, $day] = array_map('intval', explode('/', $monthDay));

            $date = JalaliDate::toGregorian($year, $month, $day);

            if ($date === null) {
                $this->warn("$year/$monthDay is not a valid date — skipped.");

                continue;
            }

            $holiday = Holiday::firstOrCreate(
                ['date' => $date->toDateString()],
                ['title' => $title, 'jalali_year' => $year],
            );

            if ($holiday->wasRecentlyCreated) {
                $added++;
            }
        }

        Holiday::forgetLookup();

        $this->info("$added holiday(ies) added for $year.");
        $this->line('Religious holidays follow the lunar calendar and must be added separately each year.');

        return self::SUCCESS;
    }
}
