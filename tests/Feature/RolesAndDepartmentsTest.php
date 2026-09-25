<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\DepartmentKind;
use App\Enums\FollowUpStep;
use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FollowUpScheduler;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may do what, and where people sit.
 *
 * The separation these tests exist for is the one every company already has
 * and most software refuses to model: the accountant sees the money and not
 * the staff file, and HR sees the staff file and not the money. Rolling both
 * into "admin" is what makes a customer decline to enter their real
 * contracts.
 */
class RolesAndDepartmentsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);
        CarbonImmutable::setTestNow('2026-09-24 09:00:00');

        $this->workspace = Workspace::factory()->create();
        $this->owner = $this->member(WorkspaceRole::Owner, 'مهراب شهیدی');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function member(WorkspaceRole $role, string $name, array $pivot = []): User
    {
        $user = User::factory()->create(['name' => $name]);

        $this->workspace->members()->attach($user, array_merge(['role' => $role->value], $pivot));

        return $user;
    }

    /**
     * Who the escalation rung would reach, walking the real path: the ladder
     * is built, and the escalation is scheduled off the chase when it fires
     * rather than up front.
     */
    private function escalationRecipient(Task $task): ?int
    {
        $scheduler = app(FollowUpScheduler::class);

        $scheduler->scheduleFor($task);

        $chase = $task->followUps()->where('step', FollowUpStep::Chase->value)->sole();

        $scheduler->scheduleEscalation($chase);

        return $task->followUps()->where('step', FollowUpStep::Escalate->value)->sole()->recipient_id;
    }

    public function test_the_accountant_sees_the_money_and_not_the_staff_file(): void
    {
        $accountant = $this->member(WorkspaceRole::Finance, 'سمیه رحیمی');

        $this->actingAs($accountant)->get(route('finance.index'))->assertOk();
        $this->actingAs($accountant)->get(route('finance.receivables'))->assertOk();

        // Contracts are readable — an accountant needs the figures — but the
        // staff list is not theirs to edit.
        $this->actingAs($accountant)->get(route('contracts.index'))->assertOk();
        $this->actingAs($accountant)->get(route('members.index'))->assertForbidden();
        $this->actingAs($accountant)->get(route('departments.index'))->assertForbidden();
    }

    public function test_human_resources_sees_the_staff_file_and_not_the_money(): void
    {
        $hr = $this->member(WorkspaceRole::HumanResources, 'زهرا کاظمی');

        $this->actingAs($hr)->get(route('members.index'))->assertOk();
        $this->actingAs($hr)->get(route('contracts.index'))->assertOk();

        $this->actingAs($hr)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($hr)->get(route('finance.receivables'))->assertForbidden();
    }

    public function test_only_the_owner_holds_the_one_permission_that_spends_money(): void
    {
        $admin = $this->member(WorkspaceRole::Admin, 'سعید کریمی');

        $this->actingAs($this->owner)->get(route('billing.index'))->assertOk();
        $this->actingAs($admin)->get(route('billing.index'))->assertForbidden();

        $this->assertTrue(WorkspaceRole::Owner->can(Permission::ManageBilling));
        $this->assertFalse(WorkspaceRole::Admin->can(Permission::ManageBilling));
    }

    public function test_an_ordinary_member_cannot_cancel_other_peoples_work(): void
    {
        $member = $this->member(WorkspaceRole::Member, 'رضا مرادی');

        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $member->id,
            'creator_id' => $this->owner->id,
        ]);

        $this->actingAs($member)->post(route('tasks.cancel', $task))->assertForbidden();
        $this->actingAs($this->owner)->post(route('tasks.cancel', $task))->assertRedirect();
    }

    public function test_a_guest_can_do_nothing_but_look_at_their_own_list(): void
    {
        $guest = $this->member(WorkspaceRole::Guest, 'مهمان');

        $this->assertSame([], WorkspaceRole::Guest->permissions());

        $this->actingAs($guest)->get(route('tasks.index'))->assertOk();

        foreach (['members.index', 'departments.index', 'finance.index', 'billing.index'] as $route) {
            $this->actingAs($guest)->get(route($route))->assertForbidden();
        }
    }

    public function test_nobody_is_promoted_to_owner_from_the_members_form(): void
    {
        // Otherwise the billing guard is decorative: an admin promotes
        // themselves and the one permission that spends money is theirs.
        $admin = $this->member(WorkspaceRole::Admin, 'سعید کریمی');
        $member = $this->member(WorkspaceRole::Member, 'رضا مرادی');

        $this->actingAs($admin)->patch(route('members.update', $member), [
            'role' => WorkspaceRole::Owner->value,
        ])->assertSessionHasErrors('role');

        $this->assertSame(
            WorkspaceRole::Member->value,
            $this->workspace->members()->where('users.id', $member->id)->first()->pivot->role,
        );
    }

    public function test_human_resources_cannot_make_themselves_an_admin(): void
    {
        // Admin carries the finance access HR is explicitly denied.
        $hr = $this->member(WorkspaceRole::HumanResources, 'زهرا کاظمی');

        $this->actingAs($hr)->patch(route('members.update', $hr), [
            'role' => WorkspaceRole::Admin->value,
        ])->assertSessionHasErrors('role');

        $this->assertSame(WorkspaceRole::HumanResources->value, $this->roleOf($hr));
    }

    public function test_human_resources_cannot_hand_out_a_role_above_their_own(): void
    {
        $hr = $this->member(WorkspaceRole::HumanResources, 'زهرا کاظمی');
        $member = $this->member(WorkspaceRole::Member, 'رضا مرادی');

        $this->actingAs($hr)->patch(route('members.update', $member), [
            'role' => WorkspaceRole::Finance->value,
        ])->assertSessionHasErrors('role');

        $this->actingAs($hr)->post(route('members.store'), [
            'name' => 'نفر تازه',
            'phone' => '09125550000',
            'role' => WorkspaceRole::Admin->value,
        ])->assertSessionHasErrors('role');

        $this->assertSame(WorkspaceRole::Member->value, $this->roleOf($member));
        $this->assertDatabaseMissing('users', ['phone' => '989125550000']);
    }

    public function test_nobody_but_the_owner_can_change_the_owners_membership(): void
    {
        $admin = $this->member(WorkspaceRole::Admin, 'سعید کریمی');

        $this->actingAs($admin)->patch(route('members.update', $this->owner), [
            'role' => WorkspaceRole::Guest->value,
        ])->assertForbidden();

        $this->assertSame(WorkspaceRole::Owner->value, $this->roleOf($this->owner));
    }

    public function test_the_owner_saving_their_own_row_stays_owner(): void
    {
        $department = Department::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'مدیریت',
            'kind' => DepartmentKind::Management,
        ]);

        $this->actingAs($this->owner)->patch(route('members.update', $this->owner), [
            'role' => WorkspaceRole::Owner->value,
            'department_id' => $department->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkspaceRole::Owner->value, $this->roleOf($this->owner));
    }

    public function test_an_escalation_climbs_to_the_department_head_before_the_owner(): void
    {
        // In a company of forty, a chase about a purchase order means
        // something to the head of commercial and nothing to the managing
        // director.
        $lead = $this->member(WorkspaceRole::Lead, 'حسین نجفی');

        $commercial = Department::factory()->for($this->workspace)
            ->kind(DepartmentKind::Commercial)
            ->create(['lead_id' => $lead->id]);

        $buyer = $this->member(WorkspaceRole::Member, 'رضا مرادی', [
            'department_id' => $commercial->id,
        ]);

        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $buyer->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->subHours(3),
        ]);

        $this->assertSame($lead->id, $this->escalationRecipient($task));
    }

    public function test_a_named_manager_still_wins_over_the_department_head(): void
    {
        $lead = $this->member(WorkspaceRole::Lead, 'حسین نجفی');
        $manager = $this->member(WorkspaceRole::Admin, 'سعید کریمی');

        $department = Department::factory()->for($this->workspace)->create(['lead_id' => $lead->id]);

        $worker = $this->member(WorkspaceRole::Member, 'رضا مرادی', [
            'department_id' => $department->id,
            'manager_id' => $manager->id,
        ]);

        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $worker->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->subHours(3),
        ]);

        $this->assertSame($manager->id, $this->escalationRecipient($task));
    }

    public function test_a_lead_is_never_escalated_to_about_their_own_work(): void
    {
        // It would be a message to nobody.
        $lead = $this->member(WorkspaceRole::Lead, 'حسین نجفی');

        $department = Department::factory()->for($this->workspace)->create(['lead_id' => $lead->id]);

        $this->workspace->members()->updateExistingPivot($lead->id, ['department_id' => $department->id]);

        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $lead->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->subHours(3),
        ]);

        $this->assertSame($this->owner->id, $this->escalationRecipient($task));
    }

    public function test_a_department_takes_its_kind_name_unless_the_company_renames_it(): void
    {
        $this->actingAs($this->owner)->post(route('departments.store'), [
            'kind' => DepartmentKind::Accounting->value,
        ])->assertRedirect();

        $this->assertSame('مالی و حسابداری', Department::sole()->name);

        $this->actingAs($this->owner)->post(route('departments.store'), [
            'kind' => DepartmentKind::Other->value,
            'name' => 'واحد کنترل کیفیت',
        ])->assertRedirect();

        $this->assertSame('واحد کنترل کیفیت', Department::latest('id')->first()->name);
    }

    public function test_archiving_a_department_keeps_its_people_and_its_history(): void
    {
        $department = Department::factory()->for($this->workspace)->create();

        $worker = $this->member(WorkspaceRole::Member, 'رضا مرادی', [
            'department_id' => $department->id,
        ]);

        $this->actingAs($this->owner)->post(route('departments.toggle', $department))->assertRedirect();

        $department->refresh();

        $this->assertFalse($department->is_active);
        $this->assertSame(
            $department->id,
            (int) $this->workspace->members()->where('users.id', $worker->id)->first()->pivot->department_id,
        );
    }

    public function test_a_department_from_another_workspace_is_a_404(): void
    {
        $other = Department::factory()->create();

        $this->actingAs($this->owner)
            ->post(route('departments.toggle', $other))
            ->assertNotFound();
    }

    public function test_a_household_has_no_departments_page(): void
    {
        $household = Workspace::factory()->type(WorkspaceType::Family)->create();
        $parent = User::factory()->create();
        $household->members()->attach($parent, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($parent)->get(route('departments.index'))->assertNotFound();
    }

    public function test_the_page_prints_the_whole_matrix_rather_than_describing_it(): void
    {
        $this->actingAs($this->owner)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertSee('نقش‌ها و دسترسی‌ها')
            ->assertSee(Permission::ViewFinance->label())
            ->assertSee(Permission::ManageMembers->label())
            ->assertSee(WorkspaceRole::Finance->label())
            ->assertSee(WorkspaceRole::HumanResources->label());
    }

    public function test_every_role_grants_only_permissions_that_exist(): void
    {
        // A typo in the matrix would otherwise hand out a permission nothing
        // checks, which reads as working and is not.
        foreach (WorkspaceRole::cases() as $role) {
            foreach ($role->permissions() as $permission) {
                $this->assertInstanceOf(Permission::class, $permission);
            }
        }

        // And the owner holds all of them, which is what makes them the
        // fallback for everything.
        $this->assertCount(count(Permission::cases()), WorkspaceRole::Owner->permissions());
    }

    private function roleOf(User $user): string
    {
        return $this->workspace->members()->where('users.id', $user->id)->first()->pivot->role;
    }
}
