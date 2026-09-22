<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStatus;
use App\Enums\TaskStatus;
use App\Models\Holiday;
use App\Models\SmsOutbound;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FollowUpRunner;
use App\Services\FollowUpScheduler;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The restraint tests. Each one is a way a follow-up engine turns into a spam
 * machine, and a customer who gets spammed cancels in month two.
 */
class SendGateTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config([
            'sms.patterns.chase.code' => 'P-CHASE',
            'sms.patterns.escalate.code' => 'P-ESCALATE',
        ]);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_does_not_text_someone_at_night_it_waits_for_morning(): void
    {
        // 23:00 local, deep inside quiet hours.
        CarbonImmutable::setTestNow('2026-09-22 19:30:00');

        $task = $this->overdueTask();
        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();

        // Deferred, not dropped: the rung is still pending, later on.
        $chase = $task->followUps()->first();
        $this->assertSame(FollowUpStatus::Pending, $chase->status);
        $this->assertTrue($chase->scheduled_at->isFuture());
    }

    public function test_a_message_held_overnight_goes_out_the_next_morning(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 19:30:00');

        $task = $this->overdueTask();
        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();

        // 08:30 local the next working day.
        CarbonImmutable::setTestNow('2026-09-23 05:00:00');
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSent('chase');
    }

    public function test_it_does_not_text_anyone_on_a_public_holiday(): void
    {
        Holiday::forgetLookup();
        Holiday::create(['date' => '2026-09-22', 'title' => 'تعطیل رسمی']);

        $task = $this->overdueTask();
        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();

        Holiday::forgetLookup();
    }

    public function test_a_critical_task_may_break_quiet_hours_when_the_manager_allowed_it(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 19:30:00');

        $task = $this->overdueTask(fn ($factory) => $factory->critical(mayBreakQuietHours: true));
        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSent('chase');
    }

    public function test_priority_alone_is_not_consent_to_break_quiet_hours(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 19:30:00');

        // Critical, but the manager never ticked the box.
        $task = $this->overdueTask(fn ($factory) => $factory->critical(mayBreakQuietHours: false));
        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
    }

    public function test_it_never_texts_someone_who_asked_it_to_stop(): void
    {
        $task = $this->overdueTask();
        $task->assignee->update(['sms_opted_out_at' => now()]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
        $this->assertSame('recipient_opted_out', $task->followUps()->first()->skip_reason);
    }

    public function test_it_stops_at_the_daily_cap(): void
    {
        $task = $this->overdueTask();

        // Three messages already today is the configured ceiling.
        SmsOutbound::factory()->count(3)->create([
            'workspace_id' => $task->workspace_id,
            'user_id' => $task->assignee_id,
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
        $this->assertSame(FollowUpStatus::Pending, $task->followUps()->first()->status);
    }

    public function test_it_keeps_ninety_minutes_between_two_messages_to_one_person(): void
    {
        $task = $this->overdueTask();

        SmsOutbound::factory()->create([
            'workspace_id' => $task->workspace_id,
            'user_id' => $task->assignee_id,
            'created_at' => now()->subMinutes(10),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
    }

    public function test_it_stops_when_the_workspace_has_spent_its_credit(): void
    {
        $workspace = Workspace::factory()->withoutSmsCredit()->create();
        $task = $this->overdueTask(workspace: $workspace);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
        $this->assertSame(
            'workspace_out_of_sms_credit',
            $task->followUps()->first()->skip_reason,
        );
    }

    public function test_the_workspace_kill_switch_stops_everything(): void
    {
        $workspace = Workspace::factory()->smsDisabled()->create();
        $task = $this->overdueTask(workspace: $workspace);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
    }

    public function test_the_global_kill_switch_stops_everything(): void
    {
        config(['sms.enabled' => false]);

        $task = $this->overdueTask();
        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
    }

    public function test_low_priority_work_is_followed_up_in_the_app_only(): void
    {
        $task = $this->overdueTask(fn ($factory) => $factory->lowPriority());

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
        $this->assertSame(
            'priority_below_sms_threshold',
            $task->followUps()->first()->skip_reason,
        );
    }

    public function test_a_task_closed_while_the_rung_waited_is_never_chased(): void
    {
        $task = $this->overdueTask();
        app(FollowUpScheduler::class)->scheduleFor($task);

        // The reply landed between scheduling and the sweep.
        $task->update(['status' => TaskStatus::Done]);

        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
        $this->assertSame('task_closed', $task->followUps()->first()->skip_reason);
    }

    public function test_it_leaves_someone_on_leave_alone(): void
    {
        $workspace = Workspace::factory()->create();
        $assignee = User::factory()->create();
        $workspace->members()->attach($assignee, [
            'role' => 'member',
            'away_until' => now()->addDays(5),
        ]);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $assignee->id,
            'creator_id' => $assignee->id,
            'due_at' => now()->subHours(3),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
        $this->assertSame('recipient_away', $task->followUps()->first()->skip_reason);
    }

    public function test_credit_is_only_spent_once_the_provider_accepts_the_message(): void
    {
        $this->sms->failEverything('provider rejected');

        $task = $this->overdueTask();
        $before = $task->workspace->sms_used;

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->assertSame($before, $task->workspace->fresh()->sms_used);
        $this->assertSame(FollowUpStatus::Failed, $task->followUps()->first()->status);
    }

    public function test_a_successful_send_consumes_credit_in_parts(): void
    {
        $task = $this->overdueTask();

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->assertGreaterThan(0, $task->workspace->fresh()->sms_used);
    }

    private function overdueTask(?callable $state = null, ?Workspace $workspace = null): Task
    {
        $workspace ??= Workspace::factory()->create();
        $assignee = User::factory()->create();
        $workspace->members()->attach($assignee, ['role' => 'member']);

        $factory = Task::factory()->for($workspace);

        if ($state !== null) {
            $factory = $state($factory);
        }

        return $factory->create([
            'assignee_id' => $assignee->id,
            'creator_id' => $assignee->id,
            'due_at' => now()->subHours(3),
        ]);
    }
}
