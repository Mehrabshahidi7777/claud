<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ReferralRewarded;
use App\Payments\Gateways\FakeGateway;
use App\Services\PaymentService;
use App\Services\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Bring a company, both of you get days." The newcomer's days arrive at
 * sign-up; the referrer's only once the newcomer pays, and only once.
 */
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $referrer;

    private User $referrerOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentGateway::class, new FakeGateway);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');

        $this->referrer = Workspace::factory()->create(['name' => 'تأسیسات پارس']);
        $this->referrerOwner = User::factory()->create();
        $this->referrer->members()->attach($this->referrerOwner, ['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_signing_up_through_a_link_adds_the_bonus_to_the_trial(): void
    {
        $code = app(ReferralService::class)->codeFor($this->referrer);

        $this->get(route('referral.capture', $code))->assertRedirect(route('login'));

        $newcomer = $this->signUp('دفتر فنی نوین');

        $this->assertTrue($newcomer->referrer->is($this->referrer));
        $this->assertEquals(
            CarbonImmutable::parse('2026-10-22 09:00:00'),
            $newcomer->subscriptions()->first()->ends_at,
        );

        // Signing someone up is free, so it earns the referrer nothing yet.
        $this->assertEquals(
            CarbonImmutable::parse('2026-10-07 09:00:00'),
            $this->referrer->subscriptions()->first()->ends_at,
        );
    }

    public function test_an_unknown_code_is_ignored(): void
    {
        $this->get(route('referral.capture', 'nosuchcode'))->assertRedirect(route('login'));

        $newcomer = $this->signUp('دفتر فنی نوین');

        $this->assertNull($newcomer->referred_by_workspace_id);
        $this->assertEquals(
            CarbonImmutable::parse('2026-10-07 09:00:00'),
            $newcomer->subscriptions()->first()->ends_at,
        );
    }

    public function test_the_referrer_is_rewarded_once_when_the_newcomer_first_pays(): void
    {
        Notification::fake();

        $this->get(route('referral.capture', app(ReferralService::class)->codeFor($this->referrer)));
        $newcomer = $this->signUp('دفتر فنی نوین');

        $this->pay($newcomer);
        $this->pay($newcomer);

        $this->assertEquals(
            CarbonImmutable::parse('2026-10-22 09:00:00'),
            $this->referrer->subscriptions()->first()->ends_at,
        );
        $this->assertNotNull($newcomer->fresh()->referral_rewarded_at);
        Notification::assertSentToTimes($this->referrerOwner, ReferralRewarded::class, 1);
    }

    public function test_the_owner_sees_their_link_and_who_came_through_it(): void
    {
        $newcomer = Workspace::factory()->create(['name' => 'دفتر فنی نوین']);
        $newcomer->forceFill(['referred_by_workspace_id' => $this->referrer->id])->save();

        $this->actingAs($this->referrerOwner)->get(route('referrals.index'))
            ->assertOk()
            ->assertSee(route('referral.capture', $this->referrer->fresh()->referral_code))
            ->assertSee('دفتر فنی نوین');
    }

    public function test_a_member_without_billing_access_cannot_open_the_page(): void
    {
        $member = User::factory()->create();
        $this->referrer->members()->attach($member, ['role' => 'member']);

        $this->actingAs($member)->get(route('referrals.index'))->assertForbidden();
    }

    private function signUp(string $workspaceName): Workspace
    {
        $user = User::factory()->create(['name' => '']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'name' => 'سارا احمدی',
            'workspace' => $workspaceName,
            'type' => 'corporate',
        ])->assertRedirect(route('dashboard'));

        return Workspace::where('name', $workspaceName)->firstOrFail();
    }

    private function pay(Workspace $workspace): void
    {
        $invoice = Invoice::factory()->for($workspace)->create();
        app(PaymentService::class)->start($invoice, route('billing.callback'));
        $payment = Payment::where('invoice_id', $invoice->id)->latest('id')->first();

        app(PaymentService::class)->settle([
            'ResNum' => $payment->res_num,
            'RefNum' => 'REF-'.$payment->id,
            'State' => 'OK',
            'TraceNo' => '123456',
            'Amount' => $payment->amount,
        ]);
    }
}
