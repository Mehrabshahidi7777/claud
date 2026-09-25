<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The daily jobs are written in office hours, and must fire in office hours
 * on a server whose clock and database both run on UTC.
 */
class ScheduleTimezoneTest extends TestCase
{
    public function test_the_receivables_chase_opens_the_tehran_morning(): void
    {
        $this->travelTo('2026-09-26 05:00:00');

        $this->assertTrue($this->eventFor('receivables:chase')->isDue($this->app));
    }

    public function test_it_does_not_fire_at_the_same_hour_in_utc(): void
    {
        $this->travelTo('2026-09-26 08:30:00');

        $this->assertFalse($this->eventFor('receivables:chase')->isDue($this->app));
    }

    private function eventFor(string $command): Event
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', $command));

        $this->assertNotNull($event, "{$command} is not scheduled");

        return $event;
    }
}
