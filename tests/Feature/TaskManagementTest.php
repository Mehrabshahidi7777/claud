<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStep;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FollowUpScheduler;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config([
            'sms.patterns.chase.code' => 'P-CHASE',
            'sms.patterns.welcome.code' => 'P-WELCOME',
        ]);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');

        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی']);
        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_creating_a_task_schedules_its_ladder_straight_away(): void
    {
        $this->actingAs($this->owner)
            ->post(route('tasks.store'), [
                'title' => 'نصب کولر گازی واحد ۱۲',
                'assignee_id' => $this->owner->id,
                'due_date' => '1405/07/15',
                'due_time' => '17:00',
                'priority' => 'normal',
            ])
            ->assertRedirect(route('tasks.index'));

        $task = Task::first();

        $this->assertNotNull($task);
        $this->assertGreaterThan(0, $task->followUps()->count());
    }

    public function test_a_jalali_deadline_is_stored_as_the_right_instant(): void
    {
        $this->actingAs($this->owner)->post(route('tasks.store'), [
            'title' => 'تحویل گزارش',
            'due_date' => '1405/07/15',
            'due_time' => '17:00',
            'priority' => 'normal',
        ]);

        // 1405/07/15 is 2026-10-07; 17:00 in Tehran is 13:30 UTC.
        $this->assertSame('2026-10-07 13:30:00', Task::first()->due_at->toDateTimeString());
    }

    public function test_a_deadline_without_a_time_lands_at_the_end_of_the_working_day(): void
    {
        $this->actingAs($this->owner)->post(route('tasks.store'), [
            'title' => 'تحویل گزارش',
            'due_date' => '1405/07/15',
            'priority' => 'normal',
        ]);

        // Not midnight, which is never what someone writing only a date means.
        $this->assertSame('13:30:00', Task::first()->due_at->format('H:i:s'));
    }

    public function test_an_impossible_jalali_date_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('tasks.store'), [
            'title' => 'تحویل گزارش',
            'due_date' => '1405/13/45',
            'priority' => 'normal',
        ])->assertSessionHasErrors();

        $this->assertSame(0, Task::count());
    }

    public function test_completing_a_task_stops_everything_still_pending(): void
    {
        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->addDay(),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);

        $this->actingAs($this->owner)->post(route('tasks.complete', $task));

        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertSame(0, $task->followUps()->where('status', 'pending')->count());
    }

    public function test_moving_a_deadline_rebuilds_the_ladder(): void
    {
        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->addDay(),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);

        $this->actingAs($this->owner)->post(route('tasks.reschedule', $task), [
            'due_date' => '1405/08/20',
            'due_time' => '12:00',
        ]);

        $chase = $task->followUps()->where('step', FollowUpStep::Chase->value)->first();

        $this->assertSame('2026-11-11', $task->fresh()->due_at->toDateString());
        $this->assertTrue($chase->scheduled_at->greaterThan(now()->addMonth()));
    }

    public function test_a_member_cannot_cancel_a_task(): void
    {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member, ['role' => 'member']);

        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $member->id,
            'creator_id' => $this->owner->id,
        ]);

        $this->actingAs($member)->post(route('tasks.cancel', $task))->assertForbidden();

        $this->assertNotSame(TaskStatus::Cancelled, $task->fresh()->status);
    }

    public function test_an_owner_can_cancel_a_task(): void
    {
        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->post(route('tasks.cancel', $task))->assertRedirect();

        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
    }

    public function test_a_task_from_another_workspace_is_invisible(): void
    {
        // The one mistake in a multi-tenant application that ends a company.
        $otherWorkspace = Workspace::factory()->create();
        $stranger = User::factory()->create();
        $otherWorkspace->members()->attach($stranger, ['role' => 'owner']);

        $theirTask = Task::factory()->for($otherWorkspace)->create([
            'assignee_id' => $stranger->id,
            'creator_id' => $stranger->id,
        ]);

        $this->actingAs($this->owner)
            ->post(route('tasks.complete', $theirTask))
            ->assertNotFound();

        $this->assertNotSame(TaskStatus::Done, $theirTask->fresh()->status);
    }

    public function test_the_task_list_only_shows_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $stranger = User::factory()->create();
        $otherWorkspace->members()->attach($stranger, ['role' => 'owner']);

        Task::factory()->for($otherWorkspace)->create([
            'title' => 'کار محرمانه شرکت دیگر',
            'assignee_id' => $stranger->id,
            'creator_id' => $stranger->id,
        ]);

        Task::factory()->for($this->workspace)->create([
            'title' => 'کار خودمان',
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('کار خودمان')
            ->assertDontSee('کار محرمانه شرکت دیگر');
    }

    public function test_an_assignee_from_another_workspace_is_refused(): void
    {
        $stranger = User::factory()->create();
        Workspace::factory()->create()->members()->attach($stranger, ['role' => 'member']);

        $this->actingAs($this->owner)->post(route('tasks.store'), [
            'title' => 'کار',
            'assignee_id' => $stranger->id,
            'priority' => 'normal',
        ])->assertSessionHasErrors('assignee_id');
    }

    public function test_adding_a_member_makes_them_assignable_before_they_ever_sign_in(): void
    {
        $this->actingAs($this->owner)->post(route('members.store'), [
            'name' => 'رضا مرادی',
            'phone' => '09121234567',
            'role' => 'member',
        ])->assertRedirect();

        $member = User::where('phone', '989121234567')->first();

        $this->assertNotNull($member);
        $this->assertNull($member->phone_verified_at, 'They have not signed in.');
        $this->sms->assertSent('welcome', '989121234567');

        // And yet a task can be assigned to them right now.
        $this->actingAs($this->owner)->post(route('tasks.store'), [
            'title' => 'نصب کولر',
            'assignee_id' => $member->id,
            'due_date' => '1405/07/15',
            'priority' => 'normal',
        ])->assertRedirect(route('tasks.index'));

        $this->assertSame($member->id, Task::first()->assignee_id);
    }

    public function test_the_same_number_is_never_two_people(): void
    {
        $existing = User::factory()->withPhone('989121234567')->create(['name' => 'رضا مرادی']);

        $this->actingAs($this->owner)->post(route('members.store'), [
            'name' => 'رضا مرادی',
            'phone' => '0912 123 4567',
            'role' => 'member',
        ]);

        $this->assertSame(1, User::where('phone', '989121234567')->count());
        $this->assertTrue($this->workspace->members()->where('users.id', $existing->id)->exists());
    }

    public function test_nobody_can_be_made_their_own_manager(): void
    {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member, ['role' => 'member']);

        $this->actingAs($this->owner)
            ->patch(route('members.update', $member), [
                'role' => 'member',
                'manager_id' => $member->id,
            ])
            ->assertSessionHasErrors('manager_id');
    }

    public function test_a_plain_member_cannot_reach_the_members_page(): void
    {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member, ['role' => 'member']);

        $this->actingAs($member)->get(route('members.index'))->assertForbidden();
    }

    public function test_signed_out_visitors_are_sent_to_sign_in(): void
    {
        $this->get(route('tasks.index'))->assertRedirect(route('login'));
        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->get(route('members.index'))->assertRedirect(route('login'));
    }
}
