<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceType;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FollowUpRunner;
use App\Services\FollowUpScheduler;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Billing without enforcement is a donation. These cover both directions:
 * an expired customer cannot add work, and — the one that costs us money —
 * the engine stops spending our SMS credit on them.
 */
class SubscriptionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config(['sms.patterns.chase.code' => 'P-CHASE']);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_signing_up_starts_a_trial(): void
    {
        // Without this a brand new customer meets the paywall before they have
        // seen the product work once.
        $user = User::factory()->create(['name' => '']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'name' => 'مهراب شهیدی',
            'workspace' => 'تأسیسات پارس',
            'type' => 'corporate',
        ])->assertRedirect(route('dashboard'));

        $subscription = Workspace::where('name', 'تأسیسات پارس')->first()->subscriptions()->first();

        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
    }

    /**
     * @return array<string, array{WorkspaceType}>
     */
    public static function workspaceTypes(): array
    {
        return [
            'corporate' => [WorkspaceType::Corporate],
            'family' => [WorkspaceType::Family],
            'friends' => [WorkspaceType::Friends],
        ];
    }

    #[DataProvider('workspaceTypes')]
    public function test_every_plan_gets_fifteen_free_days_on_its_own_plan(WorkspaceType $type): void
    {
        $user = User::factory()->create(['name' => '']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'name' => 'مهراب شهیدی',
            'workspace' => 'فضای آزمایشی',
            'type' => $type->value,
        ]);

        $subscription = Workspace::where('name', 'فضای آزمایشی')->first()->subscriptions()->first();

        $this->assertSame($type->planKey(), $subscription->plan_key);
        $this->assertEquals(CarbonImmutable::parse('2026-10-07 09:00:00'), $subscription->ends_at);
    }

    public function test_a_trialing_workspace_can_create_work(): void
    {
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Trialing);

        $this->actingAs($owner)->post(route('tasks.store'), [
            'title' => 'نصب کولر',
            'priority' => 'normal',
        ])->assertRedirect(route('tasks.index'));

        $this->assertSame(1, Task::count());
    }

    public function test_an_expired_workspace_cannot_create_work(): void
    {
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Expired);

        $this->actingAs($owner)->post(route('tasks.store'), [
            'title' => 'نصب کولر',
            'priority' => 'normal',
        ])->assertRedirect(route('billing.index'));

        $this->assertSame(0, Task::count());
    }

    public function test_an_expired_workspace_can_still_read_everything(): void
    {
        // Locking a customer out of their own data makes them angry, not
        // solvent. They ring to complain instead of to pay.
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Expired);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $this->actingAs($owner)->get(route('reports.index'))->assertOk();
    }

    public function test_an_expired_workspace_can_still_reach_the_page_that_fixes_it(): void
    {
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Expired);

        $this->actingAs($owner)->get(route('billing.index'))->assertOk();

        $this->actingAs($owner)->post(route('billing.store'), [
            'plan_key' => 'corporate',
            'seats' => 5,
            'term' => 'monthly',
        ])->assertRedirect();
    }

    public function test_a_workspace_in_grace_is_still_fully_usable(): void
    {
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Grace);

        $this->actingAs($owner)->post(route('tasks.store'), [
            'title' => 'نصب کولر',
            'priority' => 'normal',
        ])->assertRedirect(route('tasks.index'));
    }

    public function test_an_expired_workspace_is_told_why_on_every_page(): void
    {
        // A redirect with no explanation just looks broken. The state persists
        // until they pay, so the banner does too.
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Expired);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('اشتراک شما تمام شده است');
    }

    public function test_a_live_workspace_sees_no_such_banner(): void
    {
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Active);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('اشتراک شما تمام شده است');
    }

    public function test_the_sign_in_page_never_shows_it(): void
    {
        // Nothing is resolvable before sign-in, and a scary banner on the
        // sign-up screen would be the worst possible first impression.
        $this->get(route('login'))->assertOk()->assertDontSee('اشتراک شما تمام شده است');
    }

    public function test_the_engine_stops_texting_for_an_expired_workspace(): void
    {
        // The message costs us money at the provider whether or not the
        // customer is paying.
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Expired);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $owner->id,
            'creator_id' => $owner->id,
            'due_at' => now()->subHours(3),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNothingSent();
        $this->assertSame('subscription_expired', $task->followUps()->first()->skip_reason);
    }

    public function test_the_engine_keeps_working_through_the_grace_period(): void
    {
        // A week of goodwill is cheaper than the churn from cutting someone
        // off mid-week.
        [$workspace, $owner] = $this->workspaceWith(SubscriptionStatus::Grace);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $owner->id,
            'creator_id' => $owner->id,
            'due_at' => now()->subHours(3),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSent('chase');
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function workspaceWith(SubscriptionStatus $status): array
    {
        $workspace = Workspace::factory()->withoutSubscription()->create();
        $owner = User::factory()->create();
        $workspace->members()->attach($owner, ['role' => 'owner']);

        Subscription::factory()->for($workspace)->create([
            'status' => $status,
            'ends_at' => $status === SubscriptionStatus::Expired ? now()->subDays(10) : now()->addDays(5),
            'grace_ends_at' => $status === SubscriptionStatus::Grace ? now()->addDays(5) : null,
        ]);

        return [$workspace, $owner];
    }
}
