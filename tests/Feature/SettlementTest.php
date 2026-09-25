<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\WorkspaceType;
use App\Models\Settlement;
use App\Models\SharedExpense;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\Workspace;
use App\Services\BalanceSheet;
use App\Services\SplitCalculator;
use App\Services\WeeklyReportDispatcher;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money between friends.
 *
 * Everything here protects one invariant: the rial always adds up. A split
 * that loses a remainder means balances that never reach zero, a group that
 * can never finish settling, and numbers nobody believes — which is the end
 * of the feature whatever else it does.
 */
class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $group;

    /** @var array<int, User> */
    private array $friends = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);
        CarbonImmutable::setTestNow('2026-09-24 09:00:00');

        $this->group = Workspace::factory()->type(WorkspaceType::Friends)->create(['name' => 'سفر شمال']);

        foreach (['رضا', 'حسین', 'امید'] as $index => $name) {
            $user = User::factory()->create(['name' => $name]);
            $this->group->members()->attach($user, ['role' => $index === 0 ? 'owner' : 'member']);
            $this->friends[] = $user;
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function addExpense(User $payer, int $amount, ?array $participants = null): void
    {
        $this->actingAs($this->friends[0])->post(route('settlements.expenses.store'), [
            'title' => 'شام',
            'amount' => $amount,
            'spent_date' => '1405/07/02',
            'payer_id' => $payer->id,
            'participants' => $participants ?? array_map(fn (User $u) => $u->id, $this->friends),
        ])->assertRedirect();
    }

    private function balances(): array
    {
        return app(BalanceSheet::class)->balances($this->group)
            ->mapWithKeys(fn (array $row) => [$row['user']->id => $row['net']])
            ->all();
    }

    public function test_a_split_that_does_not_divide_evenly_still_adds_up_exactly(): void
    {
        // 10,000 between three is 3,333 each and one rial with nowhere to go.
        // Dropping it is how every expense quietly leaks.
        $shares = (new SplitCalculator)->equally(10_000, [7, 3, 5]);

        $this->assertSame(10_000, array_sum($shares));
        $this->assertCount(3, $shares);

        // Stable: the same input must always give the same answer, or an edit
        // moves a rial between two friends for no visible reason.
        $this->assertSame($shares, (new SplitCalculator)->equally(10_000, [5, 7, 3]));
    }

    public function test_the_shares_of_a_stored_expense_sum_to_what_was_paid(): void
    {
        $this->addExpense($this->friends[0], 1_000_000);

        $expense = SharedExpense::sole();

        $this->assertSame(1_000_000, $expense->shares()->sum('amount'));
    }

    public function test_everybody_nets_to_zero_however_the_money_moved(): void
    {
        // The invariant the whole sheet rests on.
        $this->addExpense($this->friends[0], 1_000_000);
        $this->addExpense($this->friends[1], 4_500_001);
        $this->addExpense($this->friends[2], 77, [$this->friends[0]->id, $this->friends[2]->id]);

        $this->assertSame(0, array_sum($this->balances()));
    }

    public function test_the_payer_is_owed_what_the_others_did_not_pay(): void
    {
        $this->addExpense($this->friends[0], 900_000);

        $balances = $this->balances();

        $this->assertSame(600_000, $balances[$this->friends[0]->id]);
        $this->assertSame(-300_000, $balances[$this->friends[1]->id]);
        $this->assertSame(-300_000, $balances[$this->friends[2]->id]);
    }

    public function test_somebody_left_out_of_a_split_owes_nothing_for_it(): void
    {
        $this->addExpense($this->friends[0], 600_000, [
            $this->friends[0]->id,
            $this->friends[1]->id,
        ]);

        $this->assertSame(0, $this->balances()[$this->friends[2]->id]);
    }

    public function test_a_tangle_becomes_the_fewest_payments_that_end_it(): void
    {
        // Three people owing each other in a loop is the case nobody
        // untangles by hand, so nobody pays.
        $this->addExpense($this->friends[0], 3_000_000);
        $this->addExpense($this->friends[1], 600_000);

        $transfers = app(BalanceSheet::class)->transfers($this->group);

        // At most one fewer than there are people.
        $this->assertLessThanOrEqual(2, count($transfers));

        $moved = array_sum(array_column($transfers, 'amount'));
        $owed = array_sum(array_filter($this->balances(), fn (int $net) => $net > 0));

        $this->assertSame($owed, $moved);
    }

    public function test_recording_the_suggested_payment_squares_everyone(): void
    {
        $this->addExpense($this->friends[0], 900_000);

        foreach (app(BalanceSheet::class)->transfers($this->group) as $transfer) {
            $this->actingAs($this->friends[0])->post(route('settlements.settle'), [
                'from_user_id' => $transfer['from']->id,
                'to_user_id' => $transfer['to']->id,
                'amount' => $transfer['amount'],
            ])->assertRedirect();
        }

        $this->assertSame([0, 0, 0], array_values($this->balances()));
        $this->assertSame([], app(BalanceSheet::class)->transfers($this->group));
    }

    public function test_a_third_friend_cannot_record_somebody_elses_debt_as_paid(): void
    {
        // رضا paid; حسین owes him. امید has no say in whether حسین paid up.
        $this->addExpense($this->friends[0], 900_000);

        $this->actingAs($this->friends[2])->post(route('settlements.settle'), [
            'from_user_id' => $this->friends[1]->id,
            'to_user_id' => $this->friends[0]->id,
            'amount' => 300_000,
        ])->assertSessionHasErrors('from_user_id');

        $this->assertDatabaseCount('settlements', 0);
    }

    public function test_a_part_payment_leaves_the_rest_owed(): void
    {
        $this->addExpense($this->friends[0], 900_000);

        $this->actingAs($this->friends[1])->post(route('settlements.settle'), [
            'from_user_id' => $this->friends[1]->id,
            'to_user_id' => $this->friends[0]->id,
            'amount' => 100_000,
        ])->assertRedirect();

        $this->assertSame(-200_000, $this->balances()[$this->friends[1]->id]);
    }

    public function test_it_refuses_to_record_more_than_is_owed(): void
    {
        // A mistyped figure must not conjure a credit that then has to be
        // argued about.
        $this->addExpense($this->friends[0], 900_000);

        $this->actingAs($this->friends[1])->post(route('settlements.settle'), [
            'from_user_id' => $this->friends[1]->id,
            'to_user_id' => $this->friends[0]->id,
            'amount' => 500_000,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Settlement::count());
    }

    public function test_it_refuses_a_payment_between_two_people_who_are_square(): void
    {
        $this->actingAs($this->friends[1])->post(route('settlements.settle'), [
            'from_user_id' => $this->friends[1]->id,
            'to_user_id' => $this->friends[0]->id,
            'amount' => 50_000,
        ])->assertSessionHasErrors('amount');
    }

    public function test_nobody_pays_themselves(): void
    {
        $this->actingAs($this->friends[0])->post(route('settlements.settle'), [
            'from_user_id' => $this->friends[0]->id,
            'to_user_id' => $this->friends[0]->id,
            'amount' => 50_000,
        ])->assertSessionHasErrors('to_user_id');
    }

    public function test_an_expense_needs_somebody_to_share_it(): void
    {
        $this->actingAs($this->friends[0])->post(route('settlements.expenses.store'), [
            'title' => 'شام',
            'amount' => 500_000,
            'spent_date' => '1405/07/02',
            'payer_id' => $this->friends[0]->id,
        ])->assertSessionHasErrors('participants');

        $this->assertSame(0, SharedExpense::count());
    }

    public function test_a_reminder_becomes_a_task_the_engine_then_chases(): void
    {
        // The decision to start it is a person's; the chasing after that is
        // the engine's.
        $this->addExpense($this->friends[0], 900_000);

        $this->actingAs($this->friends[0])->post(route('settlements.remind'), [
            'from_user_id' => $this->friends[1]->id,
            'to_user_id' => $this->friends[0]->id,
        ])->assertRedirect();

        $task = Task::sole();

        $this->assertSame($this->friends[1]->id, $task->assignee_id);
        $this->assertStringContainsString('تسویه حساب با رضا', $task->title);
        $this->assertStringContainsString('300,000', $task->description);
        $this->assertTrue($task->followUps()->exists());
    }

    public function test_a_second_reminder_does_not_stack_on_the_first(): void
    {
        $this->addExpense($this->friends[0], 900_000);

        $payload = [
            'from_user_id' => $this->friends[1]->id,
            'to_user_id' => $this->friends[0]->id,
        ];

        $this->actingAs($this->friends[0])->post(route('settlements.remind'), $payload);
        $this->actingAs($this->friends[0])->post(route('settlements.remind'), $payload)
            ->assertSessionHasErrors('remind');

        $this->assertSame(1, Task::count());
    }

    public function test_there_is_nothing_to_remind_somebody_who_owes_nothing(): void
    {
        $this->actingAs($this->friends[0])->post(route('settlements.remind'), [
            'from_user_id' => $this->friends[1]->id,
            'to_user_id' => $this->friends[0]->id,
        ])->assertSessionHasErrors('remind');

        $this->assertSame(0, Task::count());
    }

    public function test_the_page_leads_with_where_the_viewer_stands(): void
    {
        $this->addExpense($this->friends[0], 900_000);

        $this->actingAs($this->friends[1])
            ->get(route('settlements.index'))
            ->assertOk()
            ->assertSee('بدهکارید')
            ->assertSee('کوتاه‌ترین راه تسویه');

        $this->actingAs($this->friends[0])
            ->get(route('settlements.index'))
            ->assertOk()
            ->assertSee('طلبکارید');
    }

    public function test_a_company_has_no_settlements_page(): void
    {
        $company = Workspace::factory()->type(WorkspaceType::Corporate)->create();
        $owner = User::factory()->create();
        $company->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)->get(route('settlements.index'))->assertNotFound();
    }

    public function test_the_weekly_report_tells_the_group_how_close_they_are(): void
    {
        config(['ai.provider' => 'null']);

        $this->addExpense($this->friends[0], 900_000);

        app(WeeklyReportDispatcher::class)->dispatchFor($this->group);

        $report = WeeklyReport::latest('id')->sole();

        $this->assertSame(2, $report->metric('settlements.transfers'));
        $this->assertSame(600_000, $report->metric('settlements.outstanding'));
        $this->assertStringContainsString('حساب همه صاف می‌شود', $report->narrative);

        // And none of the company sections.
        $this->assertNull($report->metric('money'));
        $this->assertNull($report->metric('contracts'));
    }
}
