<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Contracts\SmsDriver;
use App\Enums\TaskStatus;
use App\Mail\WeeklyReportMail;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\Workspace;
use App\Services\WeeklyReportDispatcher;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config(['sms.patterns.weekly_report.code' => 'P-WEEKLY']);

        // Saturday 08:00 in Tehran, which is the moment the command looks for.
        CarbonImmutable::setTestNow('2026-09-26 04:30:00');

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Tehran']);
        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی', 'email' => 'owner@example.test']);
        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_generates_a_report_and_emails_it(): void
    {
        Mail::fake();

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertNotNull($report);
        Mail::assertSent(WeeklyReportMail::class);
        $this->assertNotNull($report->fresh()->emailed_at);
    }

    public function test_it_also_texts_the_headline_because_email_may_not_arrive(): void
    {
        Mail::fake();

        app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->sms->assertSent('weekly_report', $this->owner->phone);
    }

    public function test_a_refusing_mail_server_does_not_cost_the_sms(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('smtp refused'));

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertNull($report->fresh()->emailed_at);
        $this->sms->assertSent('weekly_report');
    }

    public function test_the_same_period_is_never_reported_twice(): void
    {
        Mail::fake();

        $first = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);
        $second = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A second run for the same period must be refused.');
        $this->assertSame(1, WeeklyReport::count());
    }

    public function test_the_snapshot_is_frozen_not_recomputed(): void
    {
        Mail::fake();

        Task::factory()->for($this->workspace)->create([
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->subDays(2),
            'status' => TaskStatus::Done,
            'completed_at' => now()->subDays(3),
        ]);

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);
        $recorded = $report->metric('headline.on_time_rate');

        // Work completed after the fact must not move a number the manager
        // has already read.
        Task::factory()->for($this->workspace)->create([
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->subDays(2),
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);

        $this->assertSame($recorded, $report->fresh()->metric('headline.on_time_rate'));
    }

    public function test_it_reports_the_change_against_the_previous_week(): void
    {
        Mail::fake();

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertArrayHasKey('on_time_rate', $report->metrics['change']);
    }

    public function test_work_due_in_the_next_three_days_is_flagged_before_it_fails(): void
    {
        Mail::fake();

        Task::factory()->for($this->workspace)->create([
            'title' => 'تحویل نقشه‌ها',
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->addDays(2),
        ]);

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertSame('تحویل نقشه‌ها', $report->metric('at_risk.0.title'));
    }

    public function test_it_marks_someone_who_is_already_behind(): void
    {
        Mail::fake();

        Task::factory()->for($this->workspace)->create([
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->subDays(1),
        ]);

        Task::factory()->for($this->workspace)->create([
            'assignee_id' => $this->owner->id,
            'creator_id' => $this->owner->id,
            'due_at' => now()->addDays(2),
        ]);

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertTrue($report->metric('at_risk.0.assignee_already_overdue'));
    }

    public function test_the_report_is_written_without_a_model_when_none_is_configured(): void
    {
        Mail::fake();

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertFalse($report->narrative_from_ai);
        $this->assertNotEmpty($report->narrative);
        // The fallback writes Persian prose, not a placeholder.
        $this->assertStringContainsString('کار', $report->narrative);
    }

    public function test_a_usable_answer_from_the_model_is_used(): void
    {
        Mail::fake();
        $this->fakeModel(str_repeat('گزارش این هفته خوب بود. ', 4));

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertTrue($report->narrative_from_ai);
        $this->assertStringContainsString('گزارش این هفته خوب بود', $report->narrative);
    }

    public function test_a_one_word_answer_from_the_model_is_rejected(): void
    {
        Mail::fake();
        $this->fakeModel('خوب');

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertFalse($report->narrative_from_ai, 'A model that answers with one word has misread the job.');
        $this->assertNotEmpty($report->narrative);
    }

    public function test_an_essay_from_the_model_is_rejected(): void
    {
        Mail::fake();
        $this->fakeModel(str_repeat('متن طولانی ', 200));

        $report = app(WeeklyReportDispatcher::class)->dispatchFor($this->workspace);

        $this->assertFalse($report->narrative_from_ai);
    }

    public function test_the_command_sends_only_at_the_workspaces_own_hour(): void
    {
        Mail::fake();

        // 13:00 in Tehran, not the configured morning.
        CarbonImmutable::setTestNow('2026-09-26 09:30:00');

        $this->artisan('reports:weekly')->assertSuccessful();

        $this->assertSame(0, WeeklyReport::count());
    }

    public function test_the_command_sends_when_that_hour_arrives(): void
    {
        Mail::fake();

        $this->artisan('reports:weekly')->assertSuccessful();

        $this->assertSame(1, WeeklyReport::count());
    }

    public function test_one_timezone_does_not_decide_for_another(): void
    {
        Mail::fake();

        // 08:00 Saturday in Tehran is 05:30 Saturday in London, so a London
        // workspace must not be reported yet.
        $london = Workspace::factory()->create(['timezone' => 'Europe/London']);
        $london->members()->attach(User::factory()->create(), ['role' => 'owner']);

        $this->artisan('reports:weekly')->assertSuccessful();

        $this->assertSame(1, WeeklyReport::count());
        $this->assertSame($this->workspace->id, WeeklyReport::first()->workspace_id);
    }

    public function test_the_report_page_opens_for_a_member_of_that_workspace(): void
    {
        $report = WeeklyReport::factory()->for($this->workspace)->create();

        $this->actingAs($this->owner)
            ->get($report->url())
            ->assertOk()
            ->assertSee($report->narrative);
    }

    public function test_the_token_is_not_authorisation(): void
    {
        // Knowing the link is not the same as being allowed to read it: the
        // report names who is behind on their work.
        $report = WeeklyReport::factory()->for($this->workspace)->create();

        $stranger = User::factory()->create();
        Workspace::factory()->create()->members()->attach($stranger, ['role' => 'owner']);

        $this->actingAs($stranger)->get($report->url())->assertNotFound();
    }

    public function test_a_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $report = WeeklyReport::factory()->for($this->workspace)->create();

        $this->get($report->url())->assertRedirect(route('login'));
    }

    public function test_the_archive_lists_past_reports(): void
    {
        WeeklyReport::factory()->for($this->workspace)->count(3)->sequence(
            ['period_start' => '2026-09-01'],
            ['period_start' => '2026-09-08'],
            ['period_start' => '2026-09-15'],
        )->create();

        $this->actingAs($this->owner)
            ->get(route('reports.weekly.index'))
            ->assertOk()
            ->assertSee('71.4٪');
    }

    public function test_the_archive_shows_nothing_from_another_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        WeeklyReport::factory()->for($otherWorkspace)->create([
            'narrative' => 'گزارش محرمانه شرکت دیگر',
        ]);

        $this->actingAs($this->owner)
            ->get(route('reports.weekly.index'))
            ->assertOk()
            ->assertDontSee('گزارش محرمانه شرکت دیگر');
    }

    private function fakeModel(string $summary): void
    {
        $this->app->instance(AiProvider::class, new class($summary) implements AiProvider
        {
            public function __construct(private readonly string $summary) {}

            public function structured(
                string $systemPrompt,
                string $userInput,
                array $schema,
                string $purpose = 'extraction',
            ): ?array {
                return ['summary' => $this->summary];
            }

            public function isAvailable(): bool
            {
                return true;
            }
        });
    }
}
