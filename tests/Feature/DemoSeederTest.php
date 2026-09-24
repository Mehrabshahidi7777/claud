<?php

namespace Tests\Feature;

use App\Models\User;
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
            ['members.index'],
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
    }
}
