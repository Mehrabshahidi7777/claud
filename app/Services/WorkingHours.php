<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Answers one question: may a message go out at this moment, and if not, when
 * is the next moment it may. Everything is computed in the workspace timezone
 * — doing it in UTC is how a chase ends up arriving at two in the morning.
 */
class WorkingHours
{
    public function __construct(private readonly Workspace $workspace) {}

    public function isWithinQuietHours(CarbonImmutable $moment): bool
    {
        $local = $moment->setTimezone($this->workspace->timezone());

        $start = $this->timeOfDay($local, config('followup.quiet_hours.start', '21:00'));
        $end = $this->timeOfDay($local, config('followup.quiet_hours.end', '08:00'));

        // Quiet hours wrap past midnight, so the window is everything at or
        // after the start OR before the end, not the range between them.
        return $local >= $start || $local < $end;
    }

    public function isHoliday(CarbonImmutable $moment): bool
    {
        $local = $moment->setTimezone($this->workspace->timezone());

        if (! in_array($local->dayOfWeek, config('followup.working_hours.week_days', []), true)) {
            return true;
        }

        return isset(Holiday::lookup()[$local->toDateString()]);
    }

    public function isSendable(CarbonImmutable $moment): bool
    {
        return ! $this->isHoliday($moment) && ! $this->isWithinQuietHours($moment);
    }

    /**
     * The next moment a message may go out. A message is never dropped for
     * arriving at a bad time — it waits.
     */
    public function nextSendableMoment(CarbonImmutable $from): CarbonImmutable
    {
        $local = $from->setTimezone($this->workspace->timezone());
        $openingTime = config('followup.working_hours.start', '08:00');

        // A full year of lookahead is far past any real holiday run and keeps
        // a misconfigured calendar from spinning forever.
        for ($i = 0; $i < 366; $i++) {
            $candidate = $i === 0 ? $local : $this->timeOfDay($local->addDays($i), $openingTime);

            if ($this->isHoliday($candidate)) {
                continue;
            }

            if (! $this->isWithinQuietHours($candidate)) {
                return $candidate->utc();
            }

            // Inside quiet hours on a working day: wait for this day to open,
            // unless the evening has already closed it.
            $opening = $this->timeOfDay($candidate, $openingTime);

            if ($candidate < $opening && ! $this->isHoliday($opening)) {
                return $opening->utc();
            }
        }

        return $from;
    }

    private function timeOfDay(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $day->setTime((int) $hour, (int) $minute);
    }
}
