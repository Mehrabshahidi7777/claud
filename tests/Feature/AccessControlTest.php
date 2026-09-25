<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\Contract;
use App\Models\Meeting;
use App\Models\Receivable;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\Drivers\FakeSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * What the roles page promises, checked at the door: "عضو: کارهای خودش",
 * finance only for finance, contracts and meetings only for whoever manages
 * them.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $company;

    private User $owner;

    private User $member;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);

        $this->company = Workspace::factory()->type(WorkspaceType::Corporate)->create();
        $this->owner = $this->join($this->company, WorkspaceRole::Owner);
        $this->member = $this->join($this->company, WorkspaceRole::Member);
        $this->colleague = $this->join($this->company, WorkspaceRole::Member);
    }

    public function test_a_company_member_sees_only_the_work_they_are_part_of(): void
    {
        $mine = $this->taskFor($this->company, $this->member, 'کار خودم');
        $theirs = $this->taskFor($this->company, $this->colleague, 'کار همکار');

        $this->actingAs($this->member)->get(route('tasks.index'))
            ->assertSee($mine->title)
            ->assertDontSee($theirs->title);

        $this->actingAs($this->member)->get(route('tasks.show', $theirs))->assertNotFound();
        $this->actingAs($this->member)->post(route('tasks.complete', $theirs))->assertNotFound();
        $this->actingAs($this->member)->get(route('tasks.show', $mine))->assertOk();

        $this->actingAs($this->owner)->get(route('tasks.index'))->assertSee($theirs->title);
    }

    public function test_a_household_shares_one_task_list_except_with_guests(): void
    {
        $family = Workspace::factory()->type(WorkspaceType::Family)->create();
        $parent = $this->join($family, WorkspaceRole::Owner);
        $child = $this->join($family, WorkspaceRole::Member);
        $guest = $this->join($family, WorkspaceRole::Guest);

        $task = $this->taskFor($family, $parent, 'تمدید بیمه ماشین');

        $this->actingAs($child)->get(route('tasks.index'))->assertSee($task->title);
        $this->actingAs($guest)->get(route('tasks.index'))->assertDontSee($task->title);
    }

    public function test_company_money_is_not_on_an_ordinary_members_dashboard(): void
    {
        Receivable::factory()->for($this->company)->overdueBy(10)->create(['amount' => 987_654_321]);

        $this->actingAs($this->member)->get(route('dashboard'))->assertDontSee('987,654,321');
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSee('987,654,321');
    }

    public function test_reports_that_name_who_is_behind_are_for_those_who_see_all_work(): void
    {
        $this->actingAs($this->member)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('reports.weekly.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('reports.index'))->assertOk();
    }

    public function test_a_member_can_neither_open_nor_end_a_contract(): void
    {
        $contract = Contract::factory()->for($this->company)->create();

        $this->actingAs($this->member)->get(route('contracts.show', $contract))->assertForbidden();
        $this->actingAs($this->member)->post(route('contracts.end', $contract))->assertForbidden();

        $this->assertTrue($contract->fresh()->isActive());
    }

    public function test_a_member_cannot_record_a_meeting_by_posting_directly(): void
    {
        $this->actingAs($this->member)->post(route('meetings.store'), [
            'title' => 'جلسه',
            'notes' => str_repeat('متن جلسه ', 5),
        ])->assertForbidden();

        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_meetings_are_read_only_by_the_roles_that_run_them(): void
    {
        $lead = $this->join($this->company, WorkspaceRole::Lead);
        $meeting = Meeting::factory()->for($this->company)->create([
            'created_by' => $this->owner->id,
            'title' => 'جلسه‌ی قیمت‌گذاری قرارداد الهیه',
        ]);

        $this->actingAs($this->member)->get(route('meetings.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertForbidden();

        $this->actingAs($lead)->get(route('meetings.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('meetings.show', $meeting))->assertOk();
    }

    public function test_a_task_from_a_meeting_does_not_reveal_the_meeting_to_its_assignee(): void
    {
        $meeting = Meeting::factory()->for($this->company)->create([
            'created_by' => $this->owner->id,
            'title' => 'جلسه‌ی قیمت‌گذاری قرارداد الهیه',
        ]);

        $task = $this->taskFor($this->company, $this->member, 'ارسال پیش‌فاکتور');
        $task->update(['meeting_id' => $meeting->id]);

        $this->actingAs($this->member)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertDontSee($meeting->title);

        $this->actingAs($this->owner)->get(route('tasks.show', $task))->assertSee($meeting->title);
    }

    public function test_re_extracting_a_meeting_counts_against_the_daily_ai_allowance(): void
    {
        config(['ai.daily_parse_limit' => 1]);
        RateLimiter::hit("ai:parse:workspace:{$this->company->id}", 86400);

        $meeting = Meeting::factory()->for($this->company)->create(['created_by' => $this->owner->id]);

        $this->actingAs($this->owner)->post(route('meetings.reparse', $meeting))
            ->assertSessionHasErrors('ai');
    }

    private function join(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        $workspace->members()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function taskFor(Workspace $workspace, User $assignee, string $title): Task
    {
        return Task::factory()->for($workspace)->create([
            'title' => $title,
            'assignee_id' => $assignee->id,
            'creator_id' => $assignee->id,
        ]);
    }
}
