<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BillingService;
use App\Sms\Drivers\FakeSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The panel for whoever sells پیگیر: every customer, and the support actions
 * a customer's own owner must never have.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config([
            'platform.admin_phones' => ['09134451502'],
            'sms.patterns.otp.code' => 'P-OTP',
        ]);

        $this->admin = User::factory()->create(['phone' => '09134451502', 'name' => '']);
    }

    public function test_a_customer_gets_a_not_found_rather_than_learning_the_panel_exists(): void
    {
        $owner = $this->customer('تأسیسات پارس', '09121110001');

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertNotFound();
        $this->actingAs($owner)->get(route('admin.workspaces.index'))->assertNotFound();
    }

    public function test_the_panel_is_closed_when_no_admin_number_is_configured(): void
    {
        config(['platform.admin_phones' => []]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertNotFound();
    }

    public function test_signing_in_with_the_admin_number_opens_the_panel(): void
    {
        RateLimiter::clear('otp:phone:989134451502');

        $this->post(route('login.request'), ['phone' => '09134451502'])->assertRedirect(route('login.code'));

        $code = collect($this->sms->sent)->last()->tokens['code'];

        $this->withSession(['otp_phone' => '989134451502'])
            ->post(route('login.verify'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_admin_without_a_workspace_is_sent_to_the_panel_not_an_error(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->admin)->get(route('tasks.index'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_anyone_else_without_a_workspace_is_sent_to_finish_signing_up(): void
    {
        $newcomer = User::factory()->create(['phone' => '09125550000', 'name' => '']);

        $this->actingAs($newcomer)->get(route('dashboard'))->assertRedirect(route('onboarding'));
    }

    public function test_the_admin_sees_every_customer_on_every_page(): void
    {
        $pars = $this->customer('تأسیسات پارس', '09121110001');
        $this->customer('خانه‌ی رضایی', '09121110002', WorkspaceType::Family);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $this->actingAs($this->admin)->get(route('admin.workspaces.index'))
            ->assertOk()
            ->assertSee('تأسیسات پارس')
            ->assertSee('خانه‌ی رضایی');

        $this->actingAs($this->admin)
            ->get(route('admin.workspaces.show', $pars->workspaces()->first()))
            ->assertOk()
            ->assertSee('09121110001');

        $this->actingAs($this->admin)->get(route('admin.payments.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.sms.index'))->assertOk();
    }

    public function test_a_support_call_finds_the_company_by_a_members_phone(): void
    {
        $this->customer('تأسیسات پارس', '09121110001');
        $this->customer('بازرگانی نوین', '09121110002');

        $this->actingAs($this->admin)->get(route('admin.workspaces.index', ['q' => '0912 111 0002']))
            ->assertSee('بازرگانی نوین')
            ->assertDontSee('تأسیسات پارس');
    }

    public function test_a_trial_about_to_end_is_on_the_dashboard(): void
    {
        $owner = $this->customer('تأسیسات پارس', '09121110001');
        $owner->workspaces()->first()->subscriptions()->update(['ends_at' => now()->addDays(3)]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('تأسیسات پارس');
    }

    public function test_granted_days_are_added_to_what_is_left_and_a_trial_stays_a_trial(): void
    {
        $workspace = $this->customer('تأسیسات پارس', '09121110001')->workspaces()->first();
        $workspace->subscriptions()->update(['ends_at' => now()->addDays(5)]);

        $this->actingAs($this->admin)
            ->post(route('admin.workspaces.extend', $workspace), ['days' => 10])
            ->assertSessionHasNoErrors();

        $current = app(BillingService::class)->currentSubscription($workspace);

        $this->assertSame(SubscriptionStatus::Trialing, $current->status);
        $this->assertEqualsWithDelta(15, now()->diffInDays($current->ends_at), 1);
    }

    public function test_granting_days_brings_a_lapsed_customer_back(): void
    {
        $workspace = Workspace::factory()->type(WorkspaceType::Family)->withoutSubscription()->create();

        $this->actingAs($this->admin)->post(route('admin.workspaces.extend', $workspace), ['days' => 30]);

        $current = app(BillingService::class)->currentSubscription($workspace);

        $this->assertSame(SubscriptionStatus::Active, $current->status);
        $this->assertSame('family', $current->plan_key);
        $this->assertEqualsWithDelta(30, now()->diffInDays($current->ends_at), 1);
    }

    public function test_a_customers_owner_cannot_grant_themselves_days(): void
    {
        $owner = $this->customer('تأسیسات پارس', '09121110001');
        $workspace = $owner->workspaces()->first();
        $before = app(BillingService::class)->currentSubscription($workspace)->ends_at;

        $this->actingAs($owner)->post(route('admin.workspaces.extend', $workspace), ['days' => 365])->assertNotFound();

        $this->assertEquals($before, app(BillingService::class)->currentSubscription($workspace)->ends_at);
    }

    public function test_the_admin_can_change_a_customers_sms_quota_and_switch(): void
    {
        $workspace = $this->customer('تأسیسات پارس', '09121110001')->workspaces()->first();

        $this->actingAs($this->admin)->post(route('admin.workspaces.sms', $workspace), ['sms_quota' => 2000]);

        $workspace->refresh();
        $this->assertSame(2000, $workspace->sms_quota);
        $this->assertFalse($workspace->sms_enabled);
    }

    public function test_a_household_trial_is_on_the_household_plan(): void
    {
        $workspace = Workspace::factory()->type(WorkspaceType::Family)->create();

        $this->assertSame('family', app(BillingService::class)->currentSubscription($workspace)->plan_key);
    }

    private function customer(string $company, string $phone, WorkspaceType $type = WorkspaceType::Corporate): User
    {
        $owner = User::factory()->create(['phone' => $phone, 'name' => 'مالک '.$company]);

        Workspace::factory()->type($type)->create(['name' => $company])
            ->members()->attach($owner, ['role' => WorkspaceRole::Owner->value]);

        return $owner;
    }
}
