<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionSweepTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config(['sms.patterns.subscription_expiring.code' => 'P-EXPIRING']);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');

        // Without a subscription of its own: each test sets up the exact
        // state it is about, and the factory's trial would collide with it.
        $this->workspace = Workspace::factory()->withoutSubscription()->create();
        $this->workspace->members()->attach(User::factory()->create(), ['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_texts_the_owner_before_a_subscription_expires(): void
    {
        Subscription::factory()->for($this->workspace)->expiringIn(3)->create();

        $this->artisan('subscriptions:sweep')->assertSuccessful();

        $this->sms->assertSent('subscription_expiring');
    }

    public function test_it_does_not_remind_the_same_threshold_twice(): void
    {
        Subscription::factory()->for($this->workspace)->expiringIn(3)->create();

        $this->artisan('subscriptions:sweep');
        $this->artisan('subscriptions:sweep');

        $this->sms->assertSentCount(1);
    }

    public function test_each_threshold_earns_its_own_reminder(): void
    {
        $subscription = Subscription::factory()->for($this->workspace)->expiringIn(7)->create();

        $this->artisan('subscriptions:sweep');

        // Four days later the three-day threshold is crossed.
        CarbonImmutable::setTestNow(now()->addDays(4));
        $this->artisan('subscriptions:sweep');

        $this->sms->assertSentCount(2);
        $this->assertTrue($subscription->fresh()->hasBeenRemindedAt(7));
        $this->assertTrue($subscription->fresh()->hasBeenRemindedAt(3));
    }

    public function test_a_subscription_far_from_expiry_is_left_alone(): void
    {
        Subscription::factory()->for($this->workspace)->expiringIn(20)->create();

        $this->artisan('subscriptions:sweep');

        $this->sms->assertNothingSent();
    }

    public function test_a_lapsed_subscription_enters_grace_rather_than_ending(): void
    {
        // Locking someone out the hour their term lapsed loses a paying
        // customer over a forgotten renewal.
        $subscription = Subscription::factory()->for($this->workspace)->create([
            'ends_at' => now()->subHour(),
        ]);

        $this->artisan('subscriptions:sweep');

        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Grace, $fresh->status);
        $this->assertTrue($fresh->grantsAccess());
    }

    public function test_grace_ends_and_access_stops(): void
    {
        $subscription = Subscription::factory()->for($this->workspace)->create([
            'status' => SubscriptionStatus::Grace,
            'ends_at' => now()->subDays(8),
            'grace_ends_at' => now()->subHour(),
        ]);

        $this->artisan('subscriptions:sweep');

        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Expired, $fresh->status);
        $this->assertFalse($fresh->grantsAccess());
    }

    public function test_a_cancelled_subscription_is_not_reminded(): void
    {
        // Cancelling was a decision, not an oversight.
        Subscription::factory()->for($this->workspace)->create([
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => now()->addDays(3),
        ]);

        $this->artisan('subscriptions:sweep');

        $this->sms->assertNothingSent();
    }

    public function test_someone_who_asked_for_no_sms_is_not_texted(): void
    {
        $this->workspace->members()->detach();
        $this->workspace->members()->attach(
            User::factory()->optedOutOfSms()->create(),
            ['role' => 'owner'],
        );

        Subscription::factory()->for($this->workspace)->expiringIn(1)->create();

        $this->artisan('subscriptions:sweep');

        $this->sms->assertNothingSent();
    }

    public function test_a_trial_about_to_end_is_reminded_too(): void
    {
        Subscription::factory()->for($this->workspace)->create([
            'status' => SubscriptionStatus::Trialing,
            'ends_at' => now()->addDays(1),
        ]);

        $this->artisan('subscriptions:sweep');

        $this->sms->assertSent('subscription_expiring');
    }
}
