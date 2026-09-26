<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every plan's SMS allowance is monthly. A yearly plan pays once a year, so
 * the month has to turn over without a payment.
 */
class SmsAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_trial_starts_with_its_own_plans_allowance(): void
    {
        $family = Workspace::factory()->type(WorkspaceType::Family)->create();

        $this->assertSame(100, $family->fresh()->sms_quota);
        $this->assertNotNull($family->fresh()->sms_period_started_at);
    }

    public function test_only_the_payer_sees_the_allowance_on_the_task_list(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace->members()->attach($owner, ['role' => 'owner']);
        $workspace->members()->attach($member, ['role' => 'member']);

        $this->actingAs($owner)->get(route('tasks.index'))->assertSee('پیامک‌های پیگیری این ماه');
        $this->actingAs($member)->get(route('tasks.index'))->assertDontSee('پیامک‌های پیگیری این ماه');
    }

    public function test_the_allowance_starts_over_once_a_month_has_passed(): void
    {
        $workspace = Workspace::factory()->create();
        $workspace->forceFill(['sms_quota' => 900, 'sms_used' => 900, 'sms_period_started_at' => now()->subMonth()])->save();

        $this->artisan('sms:reset-allowance')->assertSuccessful();

        $workspace->refresh();
        $this->assertSame(0, $workspace->sms_used);
        // A quota the platform owner raised by hand survives the new month.
        $this->assertSame(900, $workspace->sms_quota);
    }

    public function test_it_leaves_a_month_that_has_not_run_out(): void
    {
        $workspace = Workspace::factory()->create();
        $workspace->forceFill(['sms_used' => 40, 'sms_period_started_at' => now()->subDays(20)])->save();

        $this->artisan('sms:reset-allowance')->assertSuccessful();

        $this->assertSame(40, $workspace->fresh()->sms_used);
    }

    public function test_a_workspace_without_a_subscription_is_left_alone(): void
    {
        $workspace = Workspace::factory()->withoutSubscription()->create();
        $workspace->forceFill(['sms_used' => 40, 'sms_period_started_at' => now()->subMonths(2)])->save();

        $this->artisan('sms:reset-allowance')->assertSuccessful();

        $this->assertSame(40, $workspace->fresh()->sms_used);
    }
}
