<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FollowUpRunner;
use App\Services\FollowUpScheduler;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        // Patterns are registered in the panel, not in code, so tests supply
        // codes for them the way a configured environment would.
        config([
            'sms.patterns.chase.code' => 'P-CHASE',
            'sms.patterns.escalate.code' => 'P-ESCALATE',
            'sms.patterns.confirm_done.code' => 'P-DONE',
            'sms.patterns.confirm_defer.code' => 'P-DEFER',
            'sms.patterns.defer_ask.code' => 'P-ASK',
            'sms.patterns.unknown.code' => 'P-UNKNOWN',
        ]);

        // A Tuesday at midday: a working day, well outside quiet hours, so a
        // test that is not about timing is not accidentally about timing.
        CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_builds_the_ladder_when_a_task_gets_a_deadline(): void
    {
        $task = $this->task(dueAt: now()->addDays(3));

        app(FollowUpScheduler::class)->scheduleFor($task);

        $steps = $this->stepsOf($task);

        $this->assertContains(FollowUpStep::Nudge->value, $steps);
        $this->assertContains(FollowUpStep::Chase->value, $steps);
    }

    public function test_the_chase_falls_two_hours_after_the_deadline(): void
    {
        $due = now()->addDays(3);
        $task = $this->task(dueAt: $due);

        app(FollowUpScheduler::class)->scheduleFor($task);

        $chase = $task->followUps()->where('step', FollowUpStep::Chase->value)->first();

        $this->assertSame(
            $due->copy()->addHours(2)->toDateTimeString(),
            $chase->scheduled_at->toDateTimeString(),
        );
    }

    public function test_a_critical_task_is_chased_at_the_deadline_itself(): void
    {
        $due = now()->addDays(3);
        $task = $this->task(dueAt: $due, state: fn ($f) => $f->critical());

        app(FollowUpScheduler::class)->scheduleFor($task);

        $chase = $task->followUps()->where('step', FollowUpStep::Chase->value)->first();

        $this->assertSame($due->toDateTimeString(), $chase->scheduled_at->toDateTimeString());
    }

    public function test_a_task_created_past_its_deadline_skips_the_reminder_rungs(): void
    {
        // Reminding someone about something already late is noise; the ladder
        // starts at the chase instead.
        $task = $this->task(dueAt: now()->subHours(5));

        app(FollowUpScheduler::class)->scheduleFor($task);

        $steps = $this->stepsOf($task);

        $this->assertNotContains(FollowUpStep::Nudge->value, $steps);
        $this->assertContains(FollowUpStep::Chase->value, $steps);
    }

    public function test_the_chase_goes_out_and_moves_the_task_on(): void
    {
        $task = $this->task(dueAt: now()->subHours(3));

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSent('chase', $task->assignee->phone);
        $this->assertSame(TaskStatus::Chased, $task->fresh()->status);
    }

    public function test_the_escalation_is_measured_from_when_the_chase_actually_went_out(): void
    {
        $task = $this->task(dueAt: now()->subHours(3));

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $escalation = $task->followUps()->where('step', FollowUpStep::Escalate->value)->first();

        $this->assertNotNull($escalation, 'Sending a chase must schedule its escalation.');
        $this->assertSame(
            now()->addHours(4)->toDateTimeString(),
            $escalation->scheduled_at->toDateTimeString(),
        );
    }

    public function test_the_escalation_addresses_the_manager_not_the_assignee(): void
    {
        $workspace = Workspace::factory()->create();
        $manager = User::factory()->create();
        $worker = User::factory()->create();

        $workspace->members()->attach($manager, ['role' => 'owner']);
        $workspace->members()->attach($worker, ['role' => 'member', 'manager_id' => $manager->id]);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $worker->id,
            'creator_id' => $manager->id,
            'due_at' => now()->subHours(3),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        // Move to when the escalation is due and sweep again. The spacing cap
        // does not apply: the two messages go to different people.
        CarbonImmutable::setTestNow(now()->addHours(5));
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSent('escalate', $manager->phone);
        $this->assertSame(TaskStatus::Escalated, $task->fresh()->status);
    }

    public function test_the_ladder_stops_after_the_escalation(): void
    {
        $task = $this->task(dueAt: now()->subHours(3));

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        CarbonImmutable::setTestNow(now()->addHours(5));
        app(FollowUpRunner::class)->sweep();

        // Two days of sweeps past the escalation must produce nothing further.
        CarbonImmutable::setTestNow(now()->addDays(2));
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSentCount(2);
    }

    public function test_an_unassigned_task_is_chased_through_its_creator(): void
    {
        $creator = User::factory()->create();
        $task = Task::factory()->create([
            'assignee_id' => null,
            'creator_id' => $creator->id,
            'due_at' => now()->subHours(3),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSent('chase', $creator->phone);
    }

    public function test_moving_the_deadline_rebuilds_the_unsent_rungs(): void
    {
        $task = $this->task(dueAt: now()->addDays(3));
        $scheduler = app(FollowUpScheduler::class);

        $scheduler->scheduleFor($task);

        $task->update(['due_at' => now()->addDays(10)]);
        $scheduler->scheduleFor($task->fresh());

        $chase = $task->followUps()->where('step', FollowUpStep::Chase->value)->first();

        $this->assertSame(
            now()->addDays(10)->addHours(2)->toDateTimeString(),
            $chase->scheduled_at->toDateTimeString(),
        );
        $this->assertSame(1, $task->followUps()->where('step', FollowUpStep::Chase->value)->count());
    }

    public function test_closing_a_task_clears_what_is_still_pending(): void
    {
        $task = $this->task(dueAt: now()->addDays(3));
        $scheduler = app(FollowUpScheduler::class);

        $scheduler->scheduleFor($task);
        $this->assertGreaterThan(0, $task->followUps()->count());

        $task->update(['status' => TaskStatus::Done]);
        $scheduler->scheduleFor($task->fresh());

        $this->assertSame(0, $task->followUps()->where('status', FollowUpStatus::Pending->value)->count());
    }

    public function test_the_same_rung_is_never_sent_twice_however_often_the_sweep_runs(): void
    {
        $task = $this->task(dueAt: now()->subHours(3));

        app(FollowUpScheduler::class)->scheduleFor($task);

        app(FollowUpRunner::class)->sweep();
        app(FollowUpRunner::class)->sweep();
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSentCount(1);
    }

    /**
     * Eloquent's pluck applies the model's casts, so `step` comes back as an
     * enum rather than the int the assertions read more clearly against.
     *
     * @return list<int>
     */
    private function stepsOf(Task $task): array
    {
        return $task->followUps()->get()
            ->map(fn ($followUp) => $followUp->step->value)
            ->all();
    }

    private function task(
        \DateTimeInterface $dueAt,
        ?callable $state = null,
    ): Task {
        $workspace = Workspace::factory()->create();
        $assignee = User::factory()->create();
        $workspace->members()->attach($assignee, ['role' => 'member']);

        $factory = Task::factory()->for($workspace);

        if ($state !== null) {
            $factory = $state($factory);
        }

        return $factory->create([
            'assignee_id' => $assignee->id,
            'creator_id' => $assignee->id,
            'due_at' => $dueAt,
        ]);
    }
}
