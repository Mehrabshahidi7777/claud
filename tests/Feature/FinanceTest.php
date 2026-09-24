<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\ExpenseCategory;
use App\Enums\ReceivableStatus;
use App\Enums\TaskStatus;
use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\Receivable;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FinanceReport;
use App\Services\ReceivableChaser;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The money module earns its place through one behaviour: an unpaid invoice
 * becomes work somebody is chased about. Every accounting package in the
 * country can already print a list of overdue invoices, and every month that
 * list goes unread.
 */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);
        CarbonImmutable::setTestNow('2026-09-24 09:00:00');

        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی']);
        $this->member = User::factory()->create(['name' => 'رضا مرادی']);

        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
        $this->workspace->members()->attach($this->member, ['role' => 'member']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_an_overdue_receivable_becomes_a_task_with_its_ladder(): void
    {
        $receivable = Receivable::factory()->for($this->workspace)->overdueBy(20)->create([
            'created_by' => $this->owner->id,
            'owner_id' => $this->member->id,
            'amount' => 420_000_000,
        ]);

        $raised = app(ReceivableChaser::class)->sweepWorkspace($this->workspace);

        $this->assertSame(1, $raised);

        $task = $receivable->refresh()->task;

        $this->assertNotNull($task, 'An overdue receivable must produce a chase task.');
        $this->assertSame($this->member->id, $task->assignee_id);
        $this->assertStringContainsString('420,000,000', $task->description);

        // It enters the ordinary ladder, which is the entire point.
        $this->assertTrue($task->followUps()->exists());
    }

    public function test_a_second_sweep_does_not_raise_a_second_task(): void
    {
        // A nightly cron that piles a new task on the same invoice every night
        // would bury the first one and burn the assignee's daily SMS cap.
        Receivable::factory()->for($this->workspace)->overdueBy(20)->create([
            'created_by' => $this->owner->id,
            'owner_id' => $this->member->id,
        ]);

        $chaser = app(ReceivableChaser::class);

        $this->assertSame(1, $chaser->sweepWorkspace($this->workspace));
        $this->assertSame(0, $chaser->sweepWorkspace($this->workspace));
    }

    public function test_an_invoice_a_day_late_is_left_alone(): void
    {
        // Usually a payment already in flight. Chasing it costs goodwill with
        // the customer and credibility with the person being chased.
        Receivable::factory()->for($this->workspace)->overdueBy(1)->create([
            'created_by' => $this->owner->id,
            'owner_id' => $this->member->id,
        ]);

        $this->assertSame(0, app(ReceivableChaser::class)->sweepWorkspace($this->workspace));
    }

    public function test_a_settled_receivable_is_never_chased(): void
    {
        Receivable::factory()->for($this->workspace)->overdueBy(40)->settled()->create([
            'created_by' => $this->owner->id,
            'owner_id' => $this->member->id,
        ]);

        $this->assertSame(0, app(ReceivableChaser::class)->sweepWorkspace($this->workspace));
    }

    public function test_a_receivable_with_no_named_owner_falls_to_the_workspace_owner(): void
    {
        // An unpaid invoice with nobody's name on it is exactly the one that
        // goes uncollected, so it must not fall through to nobody.
        $receivable = Receivable::factory()->for($this->workspace)->overdueBy(20)->create([
            'created_by' => $this->owner->id,
            'owner_id' => null,
        ]);

        app(ReceivableChaser::class)->sweepWorkspace($this->workspace);

        $this->assertSame($this->owner->id, $receivable->refresh()->task->assignee_id);
    }

    public function test_paying_in_full_closes_the_chase_task(): void
    {
        $receivable = Receivable::factory()->for($this->workspace)->overdueBy(20)->create([
            'created_by' => $this->owner->id,
            'owner_id' => $this->member->id,
            'amount' => 100_000_000,
        ]);

        app(ReceivableChaser::class)->sweepWorkspace($this->workspace);

        $this->actingAs($this->owner)
            ->post(route('finance.receivables.settle', $receivable), ['received' => 100_000_000])
            ->assertRedirect();

        $receivable->refresh();

        $this->assertSame(ReceivableStatus::Settled, $receivable->status);
        $this->assertSame(TaskStatus::Done, $receivable->task->refresh()->status);
    }

    public function test_a_part_payment_keeps_being_chased_for_the_rest(): void
    {
        $receivable = Receivable::factory()->for($this->workspace)->overdueBy(20)->create([
            'created_by' => $this->owner->id,
            'owner_id' => $this->member->id,
            'amount' => 100_000_000,
        ]);

        app(ReceivableChaser::class)->sweepWorkspace($this->workspace);

        $this->actingAs($this->owner)
            ->post(route('finance.receivables.settle', $receivable), ['received' => 30_000_000])
            ->assertRedirect();

        $receivable->refresh();

        $this->assertSame(ReceivableStatus::Partial, $receivable->status);
        $this->assertSame(70_000_000, $receivable->outstanding());
        $this->assertSame(TaskStatus::Open, $receivable->task->refresh()->status);
    }

    public function test_it_refuses_to_record_more_than_is_owed(): void
    {
        $receivable = Receivable::factory()->for($this->workspace)->create([
            'created_by' => $this->owner->id,
            'amount' => 100_000_000,
        ]);

        $this->actingAs($this->owner)
            ->post(route('finance.receivables.settle', $receivable), ['received' => 150_000_000])
            ->assertSessionHasErrors('received');

        $this->assertSame(ReceivableStatus::Open, $receivable->refresh()->status);
    }

    public function test_a_due_date_before_the_issue_date_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('finance.receivables.store'), [
            'customer_name' => 'شرکت نگین',
            'title' => 'صورت‌وضعیت ۳',
            'amount' => 1_000_000,
            'issued_date' => '1405/08/01',
            'due_date' => '1405/07/01',
        ])->assertSessionHasErrors('due_date');

        $this->assertSame(0, Receivable::count());
    }

    public function test_an_approved_purchase_with_no_receipt_is_reported(): void
    {
        // The one number here a manager cannot get anywhere else: money the
        // company said yes to, with no trace of where it went.
        $stale = ApprovalRequest::factory()->for($this->workspace)->purchase()->approved()->create([
            'requester_id' => $this->member->id,
            'title' => 'خرید دستگاه جوش',
            'decided_at' => now()->subDays(30),
        ]);

        $recent = ApprovalRequest::factory()->for($this->workspace)->purchase()->approved()->create([
            'requester_id' => $this->member->id,
            'title' => 'خرید لپ‌تاپ',
            'decided_at' => now()->subDays(2),
        ]);

        $this->actingAs($this->owner)
            ->get(route('finance.index'))
            ->assertOk()
            ->assertSee('خرید دستگاه جوش')
            // Two weeks of grace before a request is held up as unaccounted.
            ->assertDontSee('خرید لپ‌تاپ');

        $this->assertNotNull($stale->id);
        $this->assertNotNull($recent->id);
    }

    public function test_filing_the_expense_clears_it_from_the_reconciliation(): void
    {
        $approval = ApprovalRequest::factory()->for($this->workspace)->purchase()->approved()->create([
            'requester_id' => $this->member->id,
            'title' => 'خرید دستگاه جوش',
            'decided_at' => now()->subDays(30),
        ]);

        $this->actingAs($this->owner)->post(route('finance.expenses.store'), [
            'title' => 'خرید دستگاه جوش',
            'category' => ExpenseCategory::Purchase->value,
            'amount' => 180_000_000,
            'spent_date' => '1405/07/12',
            'approval_request_id' => $approval->id,
        ])->assertRedirect();

        $this->actingAs($this->owner)
            ->get(route('finance.index'))
            ->assertOk()
            ->assertSee('همه‌ی تأییدیه‌ها فاکتور خورده‌اند');
    }

    public function test_an_unparseable_jalali_date_is_refused_rather_than_stored_as_today(): void
    {
        $this->actingAs($this->owner)->post(route('finance.expenses.store'), [
            'title' => 'خرید',
            'category' => ExpenseCategory::Purchase->value,
            'amount' => 1_000_000,
            'spent_date' => '1405/13/45',
        ])->assertSessionHasErrors('spent_date');

        $this->assertSame(0, Expense::count());
    }

    public function test_the_money_pages_are_not_open_to_ordinary_members(): void
    {
        foreach (['finance.index', 'finance.expenses', 'finance.receivables'] as $route) {
            $this->actingAs($this->member)->get(route($route))->assertForbidden();
        }

        $this->actingAs($this->member)->post(route('finance.expenses.store'), [
            'title' => 'خرید',
            'category' => ExpenseCategory::Purchase->value,
            'amount' => 1_000_000,
            'spent_date' => '1405/07/12',
        ])->assertForbidden();
    }

    public function test_the_header_does_not_dangle_a_link_a_member_cannot_open(): void
    {
        $this->actingAs($this->member)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertDontSee(route('finance.index'));

        $this->actingAs($this->owner)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertSee(route('finance.index'));
    }

    public function test_a_receivable_from_another_workspace_is_a_404(): void
    {
        $other = Receivable::factory()->create();

        $this->actingAs($this->owner)
            ->post(route('finance.receivables.settle', $other), ['received' => 1])
            ->assertNotFound();
    }

    public function test_spending_is_grouped_by_category_with_a_comparison(): void
    {
        Expense::factory()->for($this->workspace)->create([
            'created_by' => $this->owner->id,
            'category' => ExpenseCategory::Payroll,
            'amount' => 900_000_000,
            'spent_on' => now()->subDays(3)->toDateString(),
        ]);

        Expense::factory()->for($this->workspace)->create([
            'created_by' => $this->owner->id,
            'category' => ExpenseCategory::Purchase,
            'amount' => 100_000_000,
            'spent_on' => now()->subDays(5)->toDateString(),
        ]);

        $report = app(FinanceReport::class)->spending(
            $this->workspace,
            CarbonImmutable::now()->subDays(29)->startOfDay(),
            CarbonImmutable::now()->endOfDay(),
        );

        $this->assertSame(1_000_000_000, $report['spent']);
        $this->assertSame(ExpenseCategory::Payroll, $report['by_category']->first()['category']);
        $this->assertSame(90.0, (float) $report['by_category']->first()['share']);
    }

    public function test_the_ageing_buckets_put_each_invoice_in_exactly_one(): void
    {
        foreach ([-5, 10, 45, 90] as $days) {
            Receivable::factory()->for($this->workspace)->create([
                'created_by' => $this->owner->id,
                'amount' => 10_000_000,
                'due_on' => now()->subDays($days)->toDateString(),
            ]);
        }

        $report = app(FinanceReport::class)->receivables($this->workspace);

        $this->assertSame(40_000_000, $report['total']);
        $this->assertSame(30_000_000, $report['overdue']);
        $this->assertSame([1, 1, 1, 1], array_column($report['buckets'], 'count'));
    }
}
