<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Payments\Gateways\FakeGateway;
use App\Services\BillingService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Most of these are the ways a payment integration loses money: a callback
 * trusted without verification, an amount taken from the request, a reference
 * replayed, a redelivered callback extending a subscription twice.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');

        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create();
        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- pricing

    public function test_the_corporate_plan_is_priced_per_seat(): void
    {
        $quote = app(BillingService::class)->quote('corporate', 12, 'monthly');

        $this->assertSame(24_000_000, $quote['subtotal']);
        $this->assertSame(2_400_000, $quote['vat']);
        $this->assertSame(26_400_000, $quote['total']);
    }

    public function test_the_family_plan_is_a_flat_price_however_many_seats(): void
    {
        $one = app(BillingService::class)->quote('family', 1, 'monthly');
        $six = app(BillingService::class)->quote('family', 6, 'monthly');

        $this->assertSame($one['subtotal'], $six['subtotal']);
    }

    public function test_a_yearly_term_is_charged_for_ten_months(): void
    {
        $billing = app(BillingService::class);

        $monthly = $billing->quote('corporate', 5, 'monthly');
        $yearly = $billing->quote('corporate', 5, 'yearly');

        $this->assertSame($monthly['subtotal'] * 10, $yearly['subtotal']);
    }

    public function test_seats_below_the_plan_minimum_are_raised_to_it(): void
    {
        // Corporate starts at five seats; asking for one does not buy a
        // cheaper corporate plan.
        $quote = app(BillingService::class)->quote('corporate', 1, 'monthly');

        $this->assertSame(5, $quote['seats']);
        $this->assertSame(10_000_000, $quote['subtotal']);
    }

    public function test_the_price_is_never_taken_from_the_request(): void
    {
        $this->actingAs($this->owner)->post(route('billing.store'), [
            'plan_key' => 'corporate',
            'seats' => 5,
            'term' => 'monthly',
            // Someone editing the form to name their own price.
            'total' => 1000,
            'subtotal' => 1000,
        ])->assertRedirect();

        $this->assertSame(11_000_000, Invoice::first()->total);
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('billing.store'), [
            'plan_key' => 'free-forever',
            'seats' => 5,
            'term' => 'monthly',
        ])->assertSessionHasErrors('plan_key');
    }

    // ---------------------------------------------------------------- settling

    public function test_a_verified_payment_marks_the_invoice_paid_and_extends_the_subscription(): void
    {
        $payment = $this->startPayment();

        $outcome = app(PaymentService::class)->settle($this->callbackFor($payment));

        $this->assertTrue($outcome['settled']);
        $this->assertSame(PaymentStatus::Verified, $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $payment->invoice->fresh()->status);

        $subscription = Subscription::where('workspace_id', $this->workspace->id)->first();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->ends_at->isFuture());
    }

    public function test_a_callback_the_gateway_declined_settles_nothing(): void
    {
        $payment = $this->startPayment();

        $outcome = app(PaymentService::class)->settle(
            $this->callbackFor($payment, ['State' => 'CanceledByUser']),
        );

        $this->assertFalse($outcome['settled']);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $payment->invoice->fresh()->status);
    }

    public function test_a_callback_claiming_success_is_worthless_if_verification_refuses(): void
    {
        // The whole point of verifying: the callback comes through the payer's
        // own browser and says whatever it was handed.
        $this->gateway->refuseVerification('transaction not found');

        $payment = $this->startPayment();

        $outcome = app(PaymentService::class)->settle($this->callbackFor($payment));

        $this->assertFalse($outcome['settled']);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $payment->invoice->fresh()->status);
    }

    public function test_a_smaller_amount_than_the_invoice_is_refused(): void
    {
        // Paying a thousand Rial for an eleven million Rial plan is the
        // classic way an Iranian gateway integration is robbed.
        $this->gateway->settlesAmount(1000);

        $payment = $this->startPayment();

        $outcome = app(PaymentService::class)->settle($this->callbackFor($payment));

        $this->assertFalse($outcome['settled']);
        $this->assertSame(InvoiceStatus::Unpaid, $payment->invoice->fresh()->status);
        $this->assertStringContainsString('amount mismatch', $payment->fresh()->failure_reason);
    }

    public function test_a_redelivered_callback_does_not_extend_the_subscription_twice(): void
    {
        $payment = $this->startPayment();
        $callback = $this->callbackFor($payment);

        app(PaymentService::class)->settle($callback);
        $firstEnd = Subscription::first()->ends_at;

        app(PaymentService::class)->settle($callback);

        $this->assertSame(1, Subscription::count());
        $this->assertEquals($firstEnd, Subscription::first()->fresh()->ends_at);
    }

    public function test_a_bank_reference_cannot_settle_two_payments(): void
    {
        $first = $this->startPayment();
        app(PaymentService::class)->settle($this->callbackFor($first, ['RefNum' => 'SHARED-REF']));

        // A second invoice, then the same bank reference replayed against it.
        $second = $this->startPayment();
        $outcome = app(PaymentService::class)->settle(
            $this->callbackFor($second, ['RefNum' => 'SHARED-REF']),
        );

        $this->assertFalse($outcome['settled']);
        $this->assertSame('replayed_reference', $outcome['reason']);
        $this->assertSame(InvoiceStatus::Unpaid, $second->invoice->fresh()->status);
    }

    public function test_a_callback_for_a_reference_we_never_issued_is_ignored(): void
    {
        $outcome = app(PaymentService::class)->settle([
            'ResNum' => 'not-ours',
            'RefNum' => 'whatever',
            'State' => 'OK',
        ]);

        $this->assertFalse($outcome['settled']);
        $this->assertSame('unknown_reference', $outcome['reason']);
    }

    public function test_an_unreachable_gateway_leaves_the_payment_open_for_retry(): void
    {
        // Unreachable is not "not paid" — the money may well have moved, so
        // the payment waits rather than being written off.
        $this->gateway->refuseVerification('unreachable: connection timed out');

        $payment = $this->startPayment();

        $outcome = app(PaymentService::class)->settle($this->callbackFor($payment));

        $this->assertFalse($outcome['settled']);
        $this->assertSame('verification_pending', $outcome['reason']);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_a_pending_payment_settles_when_the_gateway_comes_back(): void
    {
        $this->gateway->refuseVerification('unreachable: connection timed out');
        $payment = $this->startPayment();
        app(PaymentService::class)->settle($this->callbackFor($payment));

        // The gateway recovers and the retry finishes the job.
        $this->app->instance(PaymentGateway::class, $this->gateway = new FakeGateway);

        $outcome = app(PaymentService::class)->verifyAndApply($payment->fresh());

        $this->assertTrue($outcome['settled']);
        $this->assertSame(InvoiceStatus::Paid, $payment->invoice->fresh()->status);
    }

    public function test_an_already_paid_invoice_cannot_be_paid_again(): void
    {
        $invoice = Invoice::factory()->for($this->workspace)->paid()->create();

        $token = app(PaymentService::class)->start($invoice, 'https://app.test/callback');

        $this->assertNull($token);
        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_gateway_that_refuses_a_token_fails_the_attempt_cleanly(): void
    {
        $this->gateway->refuseTokens();

        $invoice = Invoice::factory()->for($this->workspace)->create();
        $token = app(PaymentService::class)->start($invoice, 'https://app.test/callback');

        $this->assertNull($token);
        $this->assertSame(PaymentStatus::Failed, Payment::first()->status);
    }

    public function test_the_amount_sent_to_the_bank_is_the_invoice_total(): void
    {
        $invoice = Invoice::factory()->for($this->workspace)->create(['total' => 26_400_000]);

        app(PaymentService::class)->start($invoice, 'https://app.test/callback');

        $this->assertSame(26_400_000, $this->gateway->requests[0]->amount);
    }

    // ------------------------------------------------------------ subscription

    public function test_an_early_renewal_adds_to_the_term_rather_than_truncating_it(): void
    {
        $existing = Subscription::factory()->for($this->workspace)->create([
            'ends_at' => now()->addDays(20),
        ]);

        $invoice = Invoice::factory()->for($this->workspace)->create();
        app(BillingService::class)->applyPaidInvoice($invoice);

        // Twenty days remaining plus a fresh month, not a month from today.
        $this->assertSame(
            $existing->ends_at->copy()->addMonth()->toDateString(),
            $existing->fresh()->ends_at->toDateString(),
        );
    }

    public function test_a_lapsed_subscription_restarts_from_today(): void
    {
        Subscription::factory()->for($this->workspace)->create([
            'ends_at' => now()->subDays(30),
        ]);

        $invoice = Invoice::factory()->for($this->workspace)->create();
        app(BillingService::class)->applyPaidInvoice($invoice);

        $this->assertSame(
            now()->addMonth()->toDateString(),
            Subscription::first()->fresh()->ends_at->toDateString(),
        );
    }

    public function test_paying_raises_the_sms_allowance_to_the_plan(): void
    {
        // Otherwise the follow-up engine stays throttled on a plan the
        // customer has already paid more for.
        $this->workspace->update(['sms_quota' => 100, 'sms_used' => 90]);

        $invoice = Invoice::factory()->for($this->workspace)->create(['plan_key' => 'corporate']);
        app(BillingService::class)->applyPaidInvoice($invoice);

        $this->assertSame(500, $this->workspace->fresh()->sms_quota);
        $this->assertSame(0, $this->workspace->fresh()->sms_used);
    }

    public function test_a_trial_grants_access_without_a_card(): void
    {
        $subscription = app(BillingService::class)->startTrial($this->workspace);

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->grantsAccess());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_subscription_in_grace_still_grants_access(): void
    {
        // Locking someone out the hour their term lapsed loses a paying
        // customer over a forgotten renewal.
        $subscription = Subscription::factory()->for($this->workspace)->inGrace()->create();

        $this->assertTrue($subscription->grantsAccess());
    }

    public function test_an_expired_subscription_does_not(): void
    {
        $subscription = Subscription::factory()->for($this->workspace)->create([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->subDays(10),
        ]);

        $this->assertFalse($subscription->grantsAccess());
    }

    // ------------------------------------------------------------- permissions

    public function test_a_plain_member_cannot_reach_billing(): void
    {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member, ['role' => 'member']);

        $this->actingAs($member)->get(route('billing.index'))->assertForbidden();
        $this->actingAs($member)->post(route('billing.store'), [
            'plan_key' => 'corporate', 'seats' => 5, 'term' => 'monthly',
        ])->assertForbidden();
    }

    public function test_another_workspaces_invoice_is_invisible(): void
    {
        $stranger = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        $otherWorkspace->members()->attach($stranger, ['role' => 'owner']);

        $theirInvoice = Invoice::factory()->for($otherWorkspace)->create();

        $this->actingAs($this->owner)
            ->get(route('billing.invoice', $theirInvoice))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->post(route('billing.pay', $theirInvoice))
            ->assertNotFound();
    }

    public function test_the_callback_route_needs_no_session(): void
    {
        // The payer returns through a redirect chain that may have dropped it,
        // and refusing them would strand a completed payment.
        $payment = $this->startPayment();

        $this->post(route('billing.callback'), $this->callbackFor($payment))
            ->assertOk()
            ->assertSee('پرداخت با موفقیت انجام و تأیید شد.');

        $this->assertSame(InvoiceStatus::Paid, $payment->invoice->fresh()->status);
    }

    // ------------------------------------------------------------------ helpers

    private function startPayment(): Payment
    {
        $invoice = Invoice::factory()->for($this->workspace)->create();

        app(PaymentService::class)->start($invoice, route('billing.callback'));

        return Payment::where('invoice_id', $invoice->id)->latest('id')->first();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function callbackFor(Payment $payment, array $overrides = []): array
    {
        return array_merge([
            'ResNum' => $payment->res_num,
            'RefNum' => 'REF-'.$payment->id,
            'State' => 'OK',
            'TraceNo' => '123456',
            'Amount' => $payment->amount,
        ], $overrides);
    }
}
