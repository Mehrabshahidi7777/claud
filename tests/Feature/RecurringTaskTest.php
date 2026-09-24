<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\RecurrenceAnchor;
use App\Enums\RecurrenceUnit;
use App\Enums\TaskStatus;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RecurrenceSweeper;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Work that comes back round.
 *
 * For a service company this is the module that makes money rather than
 * reporting it: a six-monthly service is only invoiced when somebody
 * remembers to ring the customer, so most of them never are. For a family
 * plan the same engine drives the car service and the insurance renewal.
 */
class RecurringTaskTest extends TestCase
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

    private function recurrence(array $attributes = []): RecurringTask
    {
        return RecurringTask::factory()->for($this->workspace)->create(array_merge([
            'created_by' => $this->owner->id,
            'assignee_id' => $this->member->id,
        ], $attributes));
    }

    private function sweep(): int
    {
        return app(RecurrenceSweeper::class)->sweepWorkspace($this->workspace);
    }

    public function test_a_service_is_raised_early_enough_to_actually_be_sold(): void
    {
        // Seven days of lead time is the difference between a service that
        // gets booked and one that is noticed on the day and missed.
        $recurrence = $this->recurrence(['lead_days' => 7, 'next_due_on' => now()->addDays(6)->toDateString()]);

        $this->assertSame(1, $this->sweep());

        $task = $recurrence->tasks()->sole();

        $this->assertStringContainsString('مجتمع تجاری الهیه', $task->title);
        $this->assertSame($this->member->id, $task->assignee_id);
        $this->assertTrue($task->followUps()->exists());
    }

    public function test_nothing_is_raised_before_the_lead_time(): void
    {
        $this->recurrence(['lead_days' => 7, 'next_due_on' => now()->addDays(30)->toDateString()]);

        $this->assertSame(0, $this->sweep());
    }

    public function test_a_nightly_sweep_does_not_pile_up_copies(): void
    {
        // Without the open-task guard the sweep raises a fresh copy every
        // night from the lead date to the due date: seven identical tasks for
        // one service, and a recipient who reads none of them.
        $recurrence = $this->recurrence(['lead_days' => 7, 'next_due_on' => now()->addDays(3)->toDateString()]);

        $this->sweep();
        $this->sweep();
        $this->sweep();

        $this->assertSame(1, $recurrence->tasks()->count());
    }

    public function test_a_paused_recurrence_raises_nothing(): void
    {
        $this->recurrence(['is_active' => false, 'next_due_on' => now()->subDays(10)->toDateString()]);

        $this->assertSame(0, $this->sweep());
    }

    public function test_a_service_done_late_gets_a_full_cycle_from_when_it_was_done(): void
    {
        // A chiller serviced two months late needs six months from the
        // service, not four — otherwise every delay quietly compounds into
        // servicing equipment that does not need it yet.
        $recurrence = $this->recurrence([
            'interval_unit' => RecurrenceUnit::Month,
            'interval_count' => 6,
            'anchor' => RecurrenceAnchor::Completion,
            'next_due_on' => now()->subMonthsNoOverflow(2)->toDateString(),
        ]);

        $this->sweep();

        $task = $recurrence->tasks()->sole();

        $this->actingAs($this->owner)->post(route('tasks.complete', $task))->assertRedirect();

        $recurrence->refresh();

        $this->assertSame(1, $recurrence->occurrences);
        $this->assertSame(now()->toDateString(), $recurrence->last_done_on->toDateString());

        $this->assertSame(
            now()->addMonthsNoOverflow(6)->toDateString(),
            $recurrence->next_due_on->toDateString(),
        );
    }

    public function test_a_fixed_date_recurrence_does_not_drift_when_done_late(): void
    {
        // A bill paid three days late must not push every future bill three
        // days later, and again, and again.
        $due = now()->subDays(3);

        $recurrence = $this->recurrence([
            'interval_unit' => RecurrenceUnit::Month,
            'interval_count' => 1,
            'anchor' => RecurrenceAnchor::Scheduled,
            'customer_name' => null,
            'next_due_on' => $due->toDateString(),
        ]);

        $this->sweep();

        $this->actingAs($this->owner)
            ->post(route('tasks.complete', $recurrence->tasks()->sole()))
            ->assertRedirect();

        $this->assertSame(
            $due->copy()->addMonthNoOverflow()->toDateString(),
            $recurrence->refresh()->next_due_on->toDateString(),
        );
    }

    public function test_a_completion_anchored_cycle_is_never_shortened_by_finishing_early(): void
    {
        $due = now()->addDays(5);

        $recurrence = $this->recurrence([
            'interval_unit' => RecurrenceUnit::Month,
            'interval_count' => 6,
            'anchor' => RecurrenceAnchor::Completion,
            'lead_days' => 7,
            'next_due_on' => $due->toDateString(),
        ]);

        $this->sweep();

        $this->actingAs($this->owner)
            ->post(route('tasks.complete', $recurrence->tasks()->sole()))
            ->assertRedirect();

        // Measured from the scheduled date, because that is the later of the
        // two — finishing five days early must not bring the next one forward.
        $this->assertSame(
            $due->copy()->addMonthsNoOverflow(6)->toDateString(),
            $recurrence->refresh()->next_due_on->toDateString(),
        );
    }

    public function test_a_long_neglected_recurrence_lands_in_the_future_not_the_past(): void
    {
        // A monthly job untouched for two years would otherwise advance by one
        // month into a date still long past, and raise a fresh task on every
        // sweep from then on.
        $recurrence = $this->recurrence([
            'interval_unit' => RecurrenceUnit::Month,
            'interval_count' => 1,
            'anchor' => RecurrenceAnchor::Scheduled,
            'customer_name' => null,
            'next_due_on' => now()->subYears(2)->toDateString(),
        ]);

        $this->sweep();

        $this->actingAs($this->owner)
            ->post(route('tasks.complete', $recurrence->tasks()->sole()))
            ->assertRedirect();

        $this->assertTrue($recurrence->refresh()->next_due_on->isFuture());
    }

    public function test_closing_by_sms_advances_the_cycle_too(): void
    {
        // The advance hangs off the model rather than the controller, so it
        // happens however the task was closed.
        $recurrence = $this->recurrence(['next_due_on' => now()->addDays(3)->toDateString()]);

        $this->sweep();

        $task = $recurrence->tasks()->sole();
        $task->update(['status' => TaskStatus::Done, 'completed_at' => now()]);

        $this->assertSame(1, $recurrence->refresh()->occurrences);
    }

    public function test_cancelling_an_occurrence_does_not_count_as_doing_it(): void
    {
        $recurrence = $this->recurrence(['next_due_on' => now()->addDays(3)->toDateString()]);

        $this->sweep();

        $this->actingAs($this->owner)
            ->post(route('tasks.cancel', $recurrence->tasks()->sole()))
            ->assertRedirect();

        $this->assertSame(0, $recurrence->refresh()->occurrences);
    }

    public function test_the_page_prices_what_forgetting_costs(): void
    {
        $this->recurrence([
            'title' => 'سرویس چیلر',
            'estimated_value' => 85_000_000,
            'next_due_on' => now()->subDays(20)->toDateString(),
        ]);

        $this->recurrence([
            'title' => 'سرویس هواساز',
            'estimated_value' => 40_000_000,
            'next_due_on' => now()->subDays(5)->toDateString(),
        ]);

        $this->recurrence([
            'title' => 'سرویس آینده',
            'estimated_value' => 999_000_000,
            'next_due_on' => now()->addMonths(5)->toDateString(),
        ]);

        $this->actingAs($this->owner)
            ->get(route('recurring.index'))
            ->assertOk()
            ->assertSee(number_format(125_000_000))
            ->assertDontSee(number_format(1_124_000_000));
    }

    public function test_a_household_recurrence_needs_no_customer_or_price(): void
    {
        // The same engine has to serve a family plan, where nobody puts a
        // number on changing the car's oil.
        $this->actingAs($this->owner)->post(route('recurring.store'), [
            'title' => 'تعویض روغن ماشین',
            'interval_unit' => RecurrenceUnit::Month->value,
            'interval_count' => 4,
            'anchor' => RecurrenceAnchor::Completion->value,
            'lead_days' => 7,
            'next_due_date' => '1405/08/15',
            'priority' => 'normal',
        ])->assertRedirect();

        $recurrence = RecurringTask::sole();

        $this->assertFalse($recurrence->isServiceContract());
        $this->assertNull($recurrence->estimated_value);

        // No price anywhere means no money tiles at all, rather than a row of
        // zeroes that reads as broken.
        $this->actingAs($this->owner)
            ->get(route('recurring.index'))
            ->assertOk()
            ->assertDontSee('درآمد در معرض از دست رفتن');
    }

    public function test_a_lead_time_longer_than_the_cycle_is_refused(): void
    {
        // It would raise the next occurrence before the current one is done,
        // forever.
        $this->actingAs($this->owner)->post(route('recurring.store'), [
            'title' => 'کار ماهانه',
            'interval_unit' => RecurrenceUnit::Month->value,
            'interval_count' => 1,
            'anchor' => RecurrenceAnchor::Scheduled->value,
            'lead_days' => 60,
            'next_due_date' => '1405/08/15',
            'priority' => 'normal',
        ])->assertSessionHasErrors('lead_days');

        $this->assertSame(0, RecurringTask::count());
    }

    public function test_an_unparseable_date_is_refused_rather_than_stored(): void
    {
        $this->actingAs($this->owner)->post(route('recurring.store'), [
            'title' => 'کار',
            'interval_unit' => RecurrenceUnit::Month->value,
            'interval_count' => 1,
            'anchor' => RecurrenceAnchor::Scheduled->value,
            'lead_days' => 3,
            'next_due_date' => '1405/13/45',
            'priority' => 'normal',
        ])->assertSessionHasErrors('next_due_date');

        $this->assertSame(0, RecurringTask::count());
    }

    public function test_pausing_keeps_the_history(): void
    {
        $recurrence = $this->recurrence(['occurrences' => 12]);

        $this->actingAs($this->owner)
            ->post(route('recurring.toggle', $recurrence))
            ->assertRedirect();

        $recurrence->refresh();

        $this->assertFalse($recurrence->is_active);
        $this->assertSame(12, $recurrence->occurrences);
    }

    public function test_a_recurrence_from_another_workspace_is_a_404(): void
    {
        $other = RecurringTask::factory()->create();

        $this->actingAs($this->owner)
            ->post(route('recurring.toggle', $other))
            ->assertNotFound();
    }

    public function test_a_six_month_cycle_from_a_long_month_does_not_drift(): void
    {
        // Adding 180 days instead of six calendar months walks the date a few
        // days every cycle; over a few years an annual service wanders into
        // the wrong season.
        $march31 = CarbonImmutable::parse('2027-03-31');

        $this->assertSame(
            '2027-09-30',
            RecurrenceUnit::Month->advance($march31, 6)->toDateString(),
        );
    }

    public function test_an_occurrence_carries_the_details_the_technician_needs(): void
    {
        $recurrence = $this->recurrence([
            'customer_name' => 'مجتمع الهیه',
            'customer_phone' => '02188776655',
            'estimated_value' => 85_000_000,
            'next_due_on' => now()->addDays(3)->toDateString(),
        ]);

        $this->sweep();

        $description = $recurrence->tasks()->sole()->description;

        $this->assertStringContainsString('مجتمع الهیه', $description);
        $this->assertStringContainsString('02188776655', $description);
        $this->assertStringContainsString('هر 6 ماه', $description);
    }

    public function test_an_unassigned_recurrence_falls_to_whoever_set_it_up(): void
    {
        $recurrence = $this->recurrence([
            'assignee_id' => null,
            'next_due_on' => now()->addDays(3)->toDateString(),
        ]);

        $this->sweep();

        $this->assertSame($this->owner->id, $recurrence->tasks()->sole()->assignee_id);
    }

    public function test_the_task_links_back_to_its_schedule(): void
    {
        $recurrence = $this->recurrence(['next_due_on' => now()->addDays(3)->toDateString()]);

        $this->sweep();

        $task = Task::sole();

        $this->assertSame($recurrence->id, $task->recurringTask->id);
    }
}
