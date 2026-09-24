<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\TaskFollowUp;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page that answers "چرا این افتاده گردن من، و چه اتفاقی برایش افتاده".
 * Everything it shows was already being recorded and shown to nobody.
 */
class TaskDetailTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);
        CarbonImmutable::setTestNow('2026-09-24 09:00:00');

        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی']);
        $this->member = User::factory()->create(['name' => 'رضا مرادی']);

        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
        $this->workspace->members()->attach($this->member, ['role' => 'member']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->workspace)->create(array_merge([
            'title' => 'تحویل نقشه‌های اجرایی',
            'assignee_id' => $this->member->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->addDay(),
        ], $attributes));
    }

    public function test_it_shows_where_the_task_came_from(): void
    {
        $meeting = Meeting::factory()->for($this->workspace)->create([
            'title' => 'جلسه هفتگی عملیات',
            'created_by' => $this->owner->id,
        ]);

        $task = $this->task(['meeting_id' => $meeting->id, 'description' => 'نقشه‌ها را کارفرما اصلاح کرده']);

        $this->actingAs($this->owner)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('جلسه هفتگی عملیات')
            ->assertSee('نقشه‌ها را کارفرما اصلاح کرده');
    }

    public function test_a_skipped_rung_explains_itself_in_words(): void
    {
        // The single most useful column in the system when someone asks why no
        // SMS arrived — and it was being written to a page nobody had.
        $task = $this->task();

        TaskFollowUp::create([
            'task_id' => $task->id,
            'step' => FollowUpStep::Chase->value,
            'channel' => 'sms',
            'recipient_id' => $this->member->id,
            'scheduled_at' => now()->subHour(),
            'status' => FollowUpStatus::Skipped,
            'skip_reason' => 'recipient_away',
        ]);

        $this->actingAs($this->owner)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('گیرنده مرخصی تأییدشده داشت')
            ->assertDontSee('recipient_away');
    }

    public function test_a_task_with_no_deadline_says_it_will_never_be_chased(): void
    {
        $task = $this->task(['due_at' => null]);

        $this->actingAs($this->owner)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('بدون ددلاین — پیگیری نمی‌شود');
    }

    public function test_the_history_names_the_engine_when_nobody_did_it(): void
    {
        $task = $this->task();

        Activity::record($task, 'task.completed_by_sms', $this->workspace->id, null);

        $this->actingAs($this->owner)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('با پاسخ پیامکی بسته شد')
            ->assertSee('سامانه');
    }

    public function test_a_member_cannot_cancel_and_is_not_offered_the_button(): void
    {
        $task = $this->task();

        $this->actingAs($this->member)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertDontSee('لغو تسک');

        $this->actingAs($this->member)
            ->post(route('tasks.cancel', $task))
            ->assertForbidden();
    }

    public function test_an_owner_can_cancel_from_the_page(): void
    {
        $task = $this->task();

        $this->actingAs($this->owner)->get(route('tasks.show', $task))->assertSee('لغو تسک');

        $this->actingAs($this->owner)
            ->post(route('tasks.cancel', $task))
            ->assertRedirect();

        $this->assertSame(TaskStatus::Cancelled, $task->refresh()->status);
    }

    public function test_rescheduling_from_the_page_moves_the_deadline(): void
    {
        $task = $this->task();

        $this->actingAs($this->owner)->post(route('tasks.reschedule', $task), [
            'due_date' => '1405/07/15',
            'due_time' => '14:30',
        ])->assertRedirect();

        $this->assertTrue($task->refresh()->due_at->greaterThan(now()->addWeek()));
    }

    public function test_a_task_from_another_workspace_is_a_404(): void
    {
        $other = Task::factory()->create();

        $this->actingAs($this->owner)
            ->get(route('tasks.show', $other))
            ->assertNotFound();
    }
}
