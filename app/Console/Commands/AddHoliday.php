<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use App\Support\JalaliDate;
use Illuminate\Console\Command;

class AddHoliday extends Command
{
    protected $signature = 'holidays:add {date : Jalali date as YYYY/MM/DD} {title}';

    protected $description = 'Add one public holiday, for the lunar dates that move each year';

    public function handle(): int
    {
        if (! preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $this->argument('date'), $matches)) {
            $this->error('Date must be Jalali, as 1405/04/12.');

            return self::FAILURE;
        }

        [, $year, $month, $day] = $matches;

        $date = JalaliDate::toGregorian((int) $year, (int) $month, (int) $day);

        if ($date === null) {
            $this->error('That is not a real Jalali date.');

            return self::FAILURE;
        }

        Holiday::updateOrCreate(
            ['date' => $date->toDateString()],
            ['title' => $this->argument('title'), 'jalali_year' => (int) $year],
        );

        Holiday::forgetLookup();

        $this->info("Added: {$this->argument('title')} — {$this->argument('date')} ({$date->toDateString()})");

        return self::SUCCESS;
    }
}
