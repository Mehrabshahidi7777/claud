<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\Workspace;
use App\Services\BalanceSheet;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The demo is what a prospect is shown, so a page that breaks in it breaks in
 * front of a customer. This walks every screen the demo covers, signed in as
 * the account the seeder tells you to sign in as.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->owner = User::where('phone', '989121110001')->sole();
    }

    /**
     * @return list<array{0: string}>
     */
    public static function demoPages(): array
    {
        return [
            ['tasks.index'],
            ['meetings.index'],
            ['approvals.index'],
            ['approvals.create'],
            ['finance.index'],
            ['finance.expenses'],
            ['finance.receivables'],
            ['finance.import'],
            ['recurring.index'],
            ['contracts.index'],
            ['members.index'],
            ['departments.index'],
            ['reports.index'],
            ['reports.weekly.index'],
            ['billing.index'],
            ['notifications.index'],
        ];
    }

    #[DataProvider('demoPages')]
    public function test_every_demo_page_renders(string $route): void
    {
        $this->actingAs($this->owner)->get(route($route))->assertOk();
    }

    public function test_the_demo_has_something_on_each_of_the_new_screens(): void
    {
        // An empty page reads as a feature that does not work, which is worse
        // than not showing the page at all.
        $this->actingAs($this->owner)
            ->get(route('meetings.index'))
            ->assertOk()
            ->assertSee('جلسه هفتگی عملیات');

        $this->actingAs($this->owner)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('مرخصی استحقاقی')
            // The clash the approval screen exists to surface: an open task
            // whose deadline falls inside the requested leave.
            ->assertSee('تحویل صورت‌وضعیت ماهانه به کارفرما')
            ->assertSee('خرید دستگاه جوش');

        // The row that sells the money module: an overdue invoice that has
        // already become somebody's task, not a line in a report nobody reads.
        $this->actingAs($this->owner)
            ->get(route('finance.index'))
            ->assertOk()
            ->assertSee('کارخانه شیمیایی پارس')
            ->assertSee('در حال پیگیری');

        // Revenue sitting there because nobody rang the customer — the figure
        // that argues for the subscription in the buyer's own currency.
        $this->actingAs($this->owner)
            ->get(route('recurring.index'))
            ->assertOk()
            ->assertSee('سرویس شش‌ماهه چیلرها')
            ->assertSee('درآمد در معرض از دست رفتن');

        // A lapsed contractor qualification is not untidy — it loses tenders
        // the company has already paid to bid for.
        $this->actingAs($this->owner)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('گواهینامه صلاحیت پیمانکاری')
            ->assertSee('منقضی شده که رها کردنش گران است');
    }

    public function test_the_demo_shows_the_permission_split_a_company_already_has(): void
    {
        // The accountant and the HR officer are the pair worth demonstrating:
        // one sees the money and not the staff file, the other the reverse.
        $accountant = User::where('phone', '989121110009')->sole();
        $hr = User::where('phone', '989121110010')->sole();

        $this->actingAs($accountant)->get(route('finance.index'))->assertOk();
        $this->actingAs($accountant)->get(route('members.index'))->assertForbidden();

        $this->actingAs($hr)->get(route('members.index'))->assertOk();
        $this->actingAs($hr)->get(route('finance.index'))->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertSee('بازرگانی')
            ->assertSee('منابع انسانی و اداری');
    }

    public function test_the_demo_ships_a_weekly_report_with_something_in_every_section(): void
    {
        // The Saturday report is what a managing director reads, so in a demo
        // it must not be the one screen that looks half-built.
        $report = WeeklyReport::latest('id')->first();

        $this->assertNotNull($report, 'The demo should arrive with a report already built.');

        foreach (['money', 'contracts', 'recurring'] as $section) {
            $this->assertNotNull($report->metric($section), "The [$section] section is missing.");
        }

        $this->assertGreaterThan(0, $report->metric('money.overdue'));
        $this->assertGreaterThan(0, $report->metric('contracts.serious'));

        $this->actingAs($this->owner)
            ->get(route('reports.weekly.show', $report->share_token))
            ->assertOk()
            ->assertSee('پول')
            ->assertSee('قراردادها و مجوزها');
    }

    public function test_the_demo_carries_a_friends_workspace_that_is_part_way_settled(): void
    {
        $group = Workspace::where('type', 'friends')->sole();

        $this->actingAs($this->owner)
            ->post(route('workspaces.switch', $group->id))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($this->owner)
            ->get(route('settlements.index'))
            ->assertOk()
            ->assertSee('اجاره ویلا')
            ->assertSee('کوتاه‌ترین راه تسویه');

        // The invariant, on real seeded data rather than a contrived case.
        $this->assertSame(
            0,
            app(BalanceSheet::class)->balances($group)->sum('net'),
        );
    }

    public function test_the_demo_carries_a_household_workspace_that_is_visibly_a_different_product(): void
    {
        // The hardest thing to explain in words: the same account, and a
        // different product. The switcher in the header shows it in a click.
        $household = Workspace::where('type', 'family')->sole();

        $this->actingAs($this->owner)
            ->post(route('workspaces.switch', $household->id))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('خانوادگی')
            ->assertDontSee(route('finance.index'))
            ->assertDontSee(route('contracts.index'));

        $this->actingAs($this->owner)->get(route('contracts.index'))->assertNotFound();
    }
}
