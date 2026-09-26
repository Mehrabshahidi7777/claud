<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\WorkspaceType;
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
 * پیگیر is free and paid for by sponsors. With billing off nothing asks for
 * money, nothing stops for want of it, and nobody is counted per head; the
 * monthly SMS allowance is the one limit left, because every SMS is real
 * money at the provider.
 */
class FreeModeTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.enabled' => false,
            'sms.patterns.chase.code' => 'P-CHASE',
            'sms.patterns.subscription_expiring.code' => 'P-EXPIRING',
        ]);

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_signing_up_gives_an_sms_allowance_and_no_trial(): void
    {
        $user = User::factory()->create(['name' => '']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'name' => 'سارا احمدی',
            'workspace' => 'خانه‌ی احمدی',
            'type' => 'family',
        ])->assertRedirect(route('dashboard'));

        $workspace = Workspace::where('name', 'خانه‌ی احمدی')->firstOrFail();
        $this->assertSame(0, $workspace->subscriptions()->count());
        $this->assertSame(100, $workspace->sms_quota);
    }

    public function test_a_workspace_with_no_subscription_works_and_is_followed_up(): void
    {
        $workspace = Workspace::factory()->withoutSubscription()->create();
        $owner = User::factory()->create();
        $workspace->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)->post(route('tasks.store'), [
            'title' => 'تمدید بیمه‌ی ماشین',
            'assignee_id' => $owner->id,
            'priority' => 'normal',
        ])->assertSessionHasNoErrors();

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $owner->id,
            'creator_id' => $owner->id,
            'due_at' => now()->subHours(3),
        ]);
        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertSent('chase');
    }

    public function test_the_billing_pages_do_not_exist_and_the_menu_does_not_offer_them(): void
    {
        $workspace = Workspace::factory()->withoutSubscription()->create();
        $owner = User::factory()->create();
        $workspace->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)->get(route('billing.index'))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('صورتحساب و اشتراک')
            ->assertDontSee('اشتراک شما تمام شده است');
    }

    public function test_nobody_is_texted_about_a_subscription_ending(): void
    {
        $workspace = Workspace::factory()->create();
        $workspace->members()->attach(User::factory()->create(), ['role' => 'owner']);
        $workspace->subscriptions()->first()->update(['ends_at' => now()->addDays(2)]);

        $this->artisan('subscriptions:sweep')->assertSuccessful();

        $this->sms->assertNothingSent();
    }

    public function test_a_company_adds_as_many_people_as_it_likes(): void
    {
        $workspace = Workspace::factory()->type(WorkspaceType::Corporate)->withoutSubscription()->create();
        $owner = User::factory()->create();
        $workspace->members()->attach($owner, ['role' => 'owner']);

        foreach (range(1, 7) as $i) {
            $this->actingAs($owner)->post(route('members.store'), [
                'name' => "همکار $i",
                'phone' => '0912555000'.$i,
                'role' => 'member',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(8, $workspace->members()->count());
    }

    public function test_the_login_page_says_free_and_shows_no_prices(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('کاملاً رایگان')
            ->assertDontSee('تومان');
    }
}
