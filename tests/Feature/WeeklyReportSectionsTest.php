<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\ContractKind;
use App\Enums\ExpenseCategory;
use App\Enums\WorkspaceType;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Receivable;
use App\Models\RecurringTask;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\Workspace;
use App\Services\ReceivableChaser;
use App\Services\WeeklyReportDispatcher;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Saturday report is the only thing a managing director reads, so it has
 * to carry everything the system knows — not just the task counts it started
 * with. Money, lapsed paperwork and unsold services all reach the same inbox.
 *
 * And it has to carry only what that customer has: a household receiving a
 * receivables section every week has been sent somebody else's report.
 */
class WeeklyReportSectionsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);
        CarbonImmutable::setTestNow('2026-09-26 08:00:00');

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Tehran']);
        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی', 'email' => 'owner@example.ir']);
        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function report(?Workspace $workspace = null): WeeklyReport
    {
        app(WeeklyReportDispatcher::class)->dispatchFor($workspace ?? $this->workspace);

        return WeeklyReport::latest('id')->sole();
    }

    public function test_the_report_carries_the_money_nobody_is_chasing(): void
    {
        Receivable::factory()->for($this->workspace)->overdueBy(40)->create([
            'created_by' => $this->owner->id,
            'customer_name' => 'کارخانه پارس',
            'amount' => 500_000_000,
        ]);

        Receivable::factory()->for($this->workspace)->create([
            'created_by' => $this->owner->id,
            'amount' => 200_000_000,
        ]);

        Expense::factory()->for($this->workspace)->create([
            'created_by' => $this->owner->id,
            'category' => ExpenseCategory::Payroll,
            'amount' => 90_000_000,
            'spent_on' => now()->subDays(2)->toDateString(),
        ]);

        $money = $this->report()->metric('money');

        $this->assertSame(700_000_000, $money['outstanding']);
        $this->assertSame(500_000_000, $money['overdue']);
        $this->assertSame(90_000_000, $money['spent']);

        // The figure that says the company is losing money to inattention
        // alone: overdue, and nobody has been asked about it.
        $this->assertSame(1, $money['unchased']);
        $this->assertSame('کارخانه پارس', $money['worst'][0]['customer']);
    }

    public function test_a_receivable_already_being_chased_is_not_counted_as_unchased(): void
    {
        Receivable::factory()->for($this->workspace)->overdueBy(40)->create([
            'created_by' => $this->owner->id,
            'owner_id' => $this->owner->id,
        ]);

        app(ReceivableChaser::class)->sweepWorkspace($this->workspace);

        $money = $this->report()->metric('money');

        $this->assertSame(0, $money['unchased']);
        $this->assertTrue($money['worst'][0]['chased']);
    }

    public function test_money_collected_this_week_is_reported(): void
    {
        $receivable = Receivable::factory()->for($this->workspace)->create([
            'created_by' => $this->owner->id,
            'amount' => 300_000_000,
        ]);

        $this->actingAs($this->owner)->post(route('finance.receivables.settle', $receivable), [
            'received' => 120_000_000,
        ])->assertRedirect();

        $this->assertSame(120_000_000, $this->report()->metric('money.collected'));
    }

    public function test_a_lapsed_qualification_is_reported_apart_from_an_untidy_one(): void
    {
        Contract::factory()->for($this->workspace)->licence()->expiredSince(20)->create([
            'created_by' => $this->owner->id,
            'title' => 'گواهینامه صلاحیت پیمانکاری',
        ]);

        Contract::factory()->for($this->workspace)->expiredSince(5)->create([
            'created_by' => $this->owner->id,
            'kind' => ContractKind::Other,
            'title' => 'قرارداد لوازم‌التحریر',
        ]);

        $contracts = $this->report()->metric('contracts');

        $this->assertSame(2, $contracts['expired']);
        $this->assertSame(1, $contracts['serious']);
        $this->assertSame('گواهینامه صلاحیت پیمانکاری', $contracts['soonest'][0]['title']);
    }

    public function test_unsold_service_revenue_reaches_the_report(): void
    {
        RecurringTask::factory()->for($this->workspace)->create([
            'created_by' => $this->owner->id,
            'title' => 'سرویس چیلر',
            'next_due_on' => now()->subDays(30)->toDateString(),
            'estimated_value' => 85_000_000,
        ]);

        $recurring = $this->report()->metric('recurring');

        $this->assertSame(1, $recurring['overdue']);
        $this->assertSame(85_000_000, $recurring['value_at_risk']);
    }

    public function test_requests_left_waiting_a_week_are_named(): void
    {
        $member = User::factory()->create(['name' => 'رضا مرادی']);
        $this->workspace->members()->attach($member, ['role' => 'member']);

        ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $member->id,
            'title' => 'مرخصی استحقاقی',
            'created_at' => now()->subDays(12),
        ]);

        ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $member->id,
            'created_at' => now()->subDay(),
        ]);

        $approvals = $this->report()->metric('approvals');

        $this->assertSame(2, $approvals['pending']);
        $this->assertSame(1, $approvals['stale']);
        $this->assertSame('مرخصی استحقاقی', $approvals['oldest'][0]['title']);
        $this->assertSame(12, $approvals['oldest'][0]['waiting_days']);
    }

    public function test_a_household_report_carries_none_of_the_company_sections(): void
    {
        $household = Workspace::factory()->type(WorkspaceType::Family)->create();
        $parent = User::factory()->create();
        $household->members()->attach($parent, ['role' => 'owner']);

        RecurringTask::factory()->for($household)->household()->create(['created_by' => $parent->id]);

        $report = $this->report($household);

        $this->assertNull($report->metric('money'));
        $this->assertNull($report->metric('contracts'));
        $this->assertNull($report->metric('approvals'));

        // What a household does have stays.
        $this->assertNotNull($report->metric('recurring'));
    }

    public function test_the_written_summary_leads_with_money_rather_than_task_counts(): void
    {
        // Without a model configured the deterministic composer writes it, and
        // it has to say the same things.
        config(['ai.provider' => 'null']);

        Receivable::factory()->for($this->workspace)->overdueBy(60)->create([
            'created_by' => $this->owner->id,
            'amount' => 900_000_000,
        ]);

        Contract::factory()->for($this->workspace)->licence()->expiredSince(10)->create([
            'created_by' => $this->owner->id,
        ]);

        $narrative = $this->report()->narrative;

        $this->assertStringContainsString('مطالبه‌ی معوق', $narrative);
        $this->assertStringContainsString('هیچ‌کس پیگیرش نیست', $narrative);
        $this->assertStringContainsString('رها کردنش گران', $narrative);
    }

    public function test_a_quiet_week_says_nothing_about_money_it_does_not_have(): void
    {
        config(['ai.provider' => 'null']);

        $narrative = $this->report()->narrative;

        $this->assertStringNotContainsString('مطالبه', $narrative);
        $this->assertStringNotContainsString('قرارداد', $narrative);
    }

    public function test_the_page_shows_the_new_sections(): void
    {
        Receivable::factory()->for($this->workspace)->overdueBy(40)->create([
            'created_by' => $this->owner->id,
            'customer_name' => 'کارخانه پارس',
            'amount' => 500_000_000,
        ]);

        Contract::factory()->for($this->workspace)->licence()->expiredSince(10)->create([
            'created_by' => $this->owner->id,
            'title' => 'گواهینامه صلاحیت پیمانکاری',
        ]);

        $report = $this->report();

        $this->actingAs($this->owner)
            ->get(route('reports.weekly.show', $report->share_token))
            ->assertOk()
            ->assertSee('کارخانه پارس')
            ->assertSee('گواهینامه صلاحیت پیمانکاری')
            ->assertSee('بدون پیگیر');
    }

    public function test_the_frozen_snapshot_keeps_the_new_figures_too(): void
    {
        // A figure that moves after the report is sent makes the whole report
        // untrustworthy, which is why the snapshot is stored rather than
        // recomputed on view.
        $receivable = Receivable::factory()->for($this->workspace)->overdueBy(40)->create([
            'created_by' => $this->owner->id,
            'amount' => 500_000_000,
        ]);

        $report = $this->report();

        $receivable->update(['settled_amount' => 500_000_000, 'status' => 'settled']);

        $this->assertSame(500_000_000, $report->fresh()->metric('money.overdue'));
    }
}
