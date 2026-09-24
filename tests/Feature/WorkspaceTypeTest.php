<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStep;
use App\Enums\WorkspaceType;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FollowUpScheduler;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three plans have to be three products, not three prices.
 *
 * Until the workspace had a type they were the same screens at different
 * rates: a family paid less and then met "صلاحیت پیمانکاری" and an escalation
 * to their manager on the second day. A plan that does not change the product
 * is a discount.
 */
class WorkspaceTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);
        CarbonImmutable::setTestNow('2026-09-24 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function workspaceOf(WorkspaceType $type): array
    {
        $workspace = Workspace::factory()->type($type)->create();
        $owner = User::factory()->create();
        $workspace->members()->attach($owner, ['role' => 'owner']);

        return [$workspace, $owner];
    }

    public function test_a_household_never_reaches_the_modules_it_does_not_have(): void
    {
        // A hidden link is still a URL somebody types, or lands on from a
        // bookmark after their plan changed.
        [, $owner] = $this->workspaceOf(WorkspaceType::Family);

        foreach ([
            'finance.index', 'finance.expenses', 'finance.receivables', 'finance.import',
            'contracts.index', 'meetings.index', 'approvals.index',
        ] as $route) {
            $this->actingAs($owner)->get(route($route))->assertNotFound();
        }
    }

    public function test_a_household_keeps_what_a_household_actually_uses(): void
    {
        [, $owner] = $this->workspaceOf(WorkspaceType::Family);

        foreach (['dashboard', 'tasks.index', 'recurring.index', 'reports.index', 'members.index'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }
    }

    public function test_a_company_keeps_everything(): void
    {
        [, $owner] = $this->workspaceOf(WorkspaceType::Corporate);

        foreach ([
            'dashboard', 'tasks.index', 'recurring.index', 'contracts.index',
            'meetings.index', 'approvals.index', 'finance.index', 'reports.index',
        ] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }
    }

    public function test_the_header_hides_what_a_household_plan_excludes(): void
    {
        [, $family] = $this->workspaceOf(WorkspaceType::Family);

        $this->actingAs($family)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('contracts.index'))
            ->assertDontSee(route('meetings.index'))
            ->assertSee(route('recurring.index'));
    }

    public function test_the_header_offers_everything_on_a_company_plan(): void
    {
        [, $corporate] = $this->workspaceOf(WorkspaceType::Corporate);

        $this->actingAs($corporate)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('contracts.index'))
            ->assertSee(route('meetings.index'));
    }

    public function test_nobody_is_escalated_to_at_home(): void
    {
        // Texting someone's spouse because the bins are still out has
        // misunderstood the home it was invited into, and that is the message
        // that gets the product uninstalled.
        [$workspace, $owner] = $this->workspaceOf(WorkspaceType::Family);

        $child = User::factory()->create();
        $workspace->members()->attach($child, ['role' => 'member', 'manager_id' => $owner->id]);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $child->id,
            'creator_id' => $owner->id,
            'due_at' => now()->addDay(),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);

        $steps = $task->followUps()->pluck('step')->map(fn ($step) => $step->value)->all();

        $this->assertNotContains(FollowUpStep::Escalate->value, $steps);
        $this->assertContains(FollowUpStep::Chase->value, $steps);
    }

    public function test_at_work_the_chase_still_climbs(): void
    {
        [$workspace, $owner] = $this->workspaceOf(WorkspaceType::Corporate);

        $member = User::factory()->create();
        $workspace->members()->attach($member, ['role' => 'member', 'manager_id' => $owner->id]);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $member->id,
            'creator_id' => $owner->id,
            'due_at' => now()->subHours(3),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);

        $chase = $task->followUps()->where('step', FollowUpStep::Chase->value)->sole();

        app(FollowUpScheduler::class)->scheduleEscalation($chase);

        $escalation = $task->followUps()->where('step', FollowUpStep::Escalate->value)->sole();

        $this->assertSame($owner->id, $escalation->recipient_id);
    }

    public function test_the_words_on_screen_follow_the_kind_of_group(): void
    {
        [, $friends] = $this->workspaceOf(WorkspaceType::Friends);

        $this->actingAs($friends)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('دوستان')
            ->assertSee('دوستانه');
    }

    public function test_the_dashboard_hides_cards_a_household_has_no_data_for(): void
    {
        // An empty receivables tile reads as a broken feature rather than as
        // one they do not have.
        [, $family] = $this->workspaceOf(WorkspaceType::Family);

        $this->actingAs($family)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('وصول‌نشده')
            ->assertSee('کارهای من');
    }

    public function test_choosing_a_type_at_sign_up_shapes_the_workspace(): void
    {
        $user = User::factory()->create(['name' => '']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'name' => 'مهراب شهیدی',
            'workspace' => 'خانه',
            'type' => WorkspaceType::Family->value,
        ])->assertRedirect(route('dashboard'));

        $workspace = Workspace::sole();

        $this->assertSame(WorkspaceType::Family, $workspace->type);
        $this->assertFalse($workspace->has('finance'));
        $this->assertTrue($workspace->has('recurring'));
    }

    public function test_sign_up_refuses_a_type_that_is_not_one_of_the_three(): void
    {
        $user = User::factory()->create(['name' => '']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'name' => 'مهراب شهیدی',
            'workspace' => 'خانه',
            'type' => 'enterprise',
        ])->assertSessionHasErrors('type');

        $this->assertSame(0, Workspace::count());
    }

    public function test_every_type_names_a_plan_that_actually_exists(): void
    {
        // A workspace must never be a family that is billed as a company.
        foreach (WorkspaceType::cases() as $type) {
            $this->assertIsArray(
                config('payment.plans.'.$type->planKey()),
                "No pricing plan configured for [{$type->value}].",
            );
        }
    }
}
