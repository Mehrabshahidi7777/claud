<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceType;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BillingService;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The company pays for everyone in it. A paid company subscription holds as
 * many people as were paid for, more places cost only the days left, and a
 * trial holds the whole team.
 */
class SeatLimitTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);
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

    public function test_a_trial_holds_more_people_than_the_minimum(): void
    {
        $this->fillTo(7);

        $this->addMember('09125550099')->assertSessionHasNoErrors();
        $this->assertSame(8, $this->workspace->members()->count());
    }

    public function test_a_paid_company_holds_as_many_people_as_it_paid_for(): void
    {
        $this->paidFor(5);
        $this->fillTo(5);

        $this->addMember('09125550099')->assertSessionHasErrors('phone');
        $this->assertSame(5, $this->workspace->members()->count());

        $this->actingAs($this->owner)->get(route('members.index'))
            ->assertSee('ظرفیت پر است')
            ->assertSee('افزایش ظرفیت');
    }

    public function test_a_household_stops_at_its_plans_ceiling_even_on_trial(): void
    {
        $family = Workspace::factory()->type(WorkspaceType::Family)->create();
        $family->members()->attach($this->owner, ['role' => 'owner']);
        foreach (range(1, 5) as $i) {
            $family->members()->attach(User::factory()->create(), ['role' => 'member']);
        }

        $this->actingAs($this->owner)
            ->withSession(['workspace_id' => $family->id])
            ->post(route('members.store'), ['name' => 'نفر هفتم', 'phone' => '09125550099', 'role' => 'member'])
            ->assertSessionHasErrors('phone');
    }

    public function test_more_places_cost_only_the_days_left_in_a_month(): void
    {
        $this->paidFor(5, endsInDays: 15);

        $quote = app(BillingService::class)->seatQuote($this->workspace, 1);

        // 200,000 Toman a month, for half of it.
        $this->assertSame(1_000_000, $quote['subtotal']);
        $this->assertSame(15, $quote['days']);
    }

    public function test_more_places_cost_only_the_days_left_in_a_year(): void
    {
        $this->paidFor(5, endsInDays: 73, term: 'yearly');

        $quote = app(BillingService::class)->seatQuote($this->workspace, 1);

        // A year is charged as ten months; a fifth of it is left.
        $this->assertSame(4_000_000, $quote['subtotal']);
    }

    public function test_paying_for_places_raises_the_limit_without_moving_the_end_date(): void
    {
        $this->paidFor(5, endsInDays: 15);
        $this->fillTo(5);
        $endsAt = $this->workspace->subscriptions()->first()->ends_at;

        $this->actingAs($this->owner)->post(route('billing.seats'), ['extra_seats' => 2])
            ->assertRedirect();

        $invoice = Invoice::where('kind', 'seats')->firstOrFail();
        $this->assertSame(2, $invoice->seats);

        app(BillingService::class)->applyPaidInvoice($invoice);

        $subscription = $this->workspace->subscriptions()->first();
        $this->assertSame(7, $subscription->seats);
        $this->assertEquals($endsAt, $subscription->ends_at);

        $this->addMember('09125550099')->assertSessionHasNoErrors();
    }

    public function test_only_someone_with_billing_access_can_buy_places(): void
    {
        $this->paidFor(5);
        $admin = User::factory()->create();
        $this->workspace->members()->attach($admin, ['role' => 'member']);

        $this->actingAs($admin)->post(route('billing.seats'), ['extra_seats' => 1])->assertForbidden();
    }

    private function paidFor(int $seats, int $endsInDays = 20, string $term = 'monthly'): void
    {
        $this->workspace->subscriptions()->first()->update([
            'status' => SubscriptionStatus::Active,
            'seats' => $seats,
            'term' => $term,
            'ends_at' => now()->addDays($endsInDays),
        ]);
    }

    private function fillTo(int $count): void
    {
        while ($this->workspace->members()->count() < $count) {
            $this->workspace->members()->attach(User::factory()->create(), ['role' => 'member']);
        }
    }

    private function addMember(string $phone): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('members.store'), [
            'name' => 'همکار تازه',
            'phone' => $phone,
            'role' => 'member',
        ]);
    }
}
