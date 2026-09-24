<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\ContractKind;
use App\Enums\ContractStatus;
use App\Enums\PartyType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContractWatcher;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contracts and licences.
 *
 * The notice window is the product here. A reminder on the day a staff
 * contract expires is worth nothing — by then the company is already carrying
 * a liability it does not know about, and a lapsed contractor qualification
 * has already lost a tender it paid to bid for.
 */
class ContractTest extends TestCase
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

    private function contract(array $attributes = []): Contract
    {
        return Contract::factory()->for($this->workspace)->create(array_merge([
            'created_by' => $this->owner->id,
            'owner_id' => $this->member->id,
        ], $attributes));
    }

    private function sweep(): int
    {
        return app(ContractWatcher::class)->sweepWorkspace($this->workspace);
    }

    public function test_a_staff_contract_is_raised_two_months_before_it_lapses(): void
    {
        $contract = $this->contract(['notice_days' => 60, 'expires_on' => now()->addDays(59)->toDateString()]);

        $this->assertSame(1, $this->sweep());

        $task = $contract->tasks()->sole();

        $this->assertStringContainsString('تمدید', $task->title);
        $this->assertSame($this->member->id, $task->assignee_id);

        // Letting a staff contract lapse is a liability, not a reminder.
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertTrue($task->followUps()->exists());
    }

    public function test_nothing_is_raised_outside_the_notice_window(): void
    {
        $this->contract(['notice_days' => 60, 'expires_on' => now()->addDays(120)->toDateString()]);

        $this->assertSame(0, $this->sweep());
    }

    public function test_an_already_lapsed_contract_is_still_picked_up(): void
    {
        // Contracts entered after they expired are the common case when a
        // company first puts its files in: the module is useless if it only
        // ever looks forward.
        $contract = $this->contract()->fill([])->refresh();
        $contract->update(['expires_on' => now()->subDays(40)->toDateString()]);

        $this->assertSame(1, $this->sweep());
        $this->assertTrue($contract->refresh()->hasExpired());
    }

    public function test_a_nightly_sweep_does_not_pile_up_sixty_tasks(): void
    {
        $contract = $this->contract(['notice_days' => 60, 'expires_on' => now()->addDays(30)->toDateString()]);

        $this->sweep();
        $this->sweep();
        $this->sweep();

        $this->assertSame(1, $contract->tasks()->count());
    }

    public function test_an_auto_renewing_contract_asks_for_a_decision_not_a_renewal(): void
    {
        // The deadline is the same but the sentence is not: one is "renew
        // this", the other is "stop it before it renews itself".
        $contract = $this->contract([
            'auto_renews' => true,
            'expires_on' => now()->addDays(20)->toDateString(),
            'notice_days' => 30,
        ]);

        $this->sweep();

        $task = $contract->tasks()->sole();

        $this->assertStringContainsString('تصمیم قبل از تمدید خودکار', $task->title);
        $this->assertStringContainsString('خودبه‌خود تمدید می‌شود', $task->description);
    }

    public function test_renewing_keeps_the_old_term_and_closes_the_task(): void
    {
        $contract = $this->contract([
            'expires_on' => now()->addDays(30)->toDateString(),
            'notice_days' => 60,
            'value' => 900_000_000,
        ]);

        $this->sweep();

        $task = $contract->tasks()->sole();
        $oldExpiry = $contract->expires_on->toDateString();

        $this->actingAs($this->owner)->post(route('contracts.renew', $contract), [
            'starts_date' => '1405/08/01',
            'expires_date' => '1406/08/01',
            'value' => 1_200_000_000,
            'note' => 'با ۳۳ درصد افزایش',
        ])->assertRedirect();

        $contract->refresh();

        $this->assertSame(1, $contract->renewals);
        $this->assertSame(1_200_000_000, $contract->value);
        $this->assertTrue($contract->expires_on->isFuture());

        // The term that just ended is history, with its own figure.
        $term = $contract->terms()->sole();
        $this->assertSame($oldExpiry, $term->expires_on->toDateString());
        $this->assertSame(900_000_000, $term->value);
        $this->assertSame('با ۳۳ درصد افزایش', $term->note);

        $this->assertSame(TaskStatus::Done, $task->refresh()->status);
    }

    public function test_a_renewal_may_run_for_a_different_length_than_the_last(): void
    {
        // A staff contract renewed six months this time and a year the next is
        // the ordinary case, which is why this is not a recurrence.
        $contract = $this->contract(['expires_on' => now()->addDays(10)->toDateString()]);

        $this->actingAs($this->owner)->post(route('contracts.renew', $contract), [
            'starts_date' => '1405/08/01',
            'expires_date' => '1406/02/01',
        ])->assertRedirect();

        $this->assertSame('2027-04-21', $contract->refresh()->expires_on->toDateString());
    }

    public function test_a_renewed_contract_is_raised_again_when_the_new_date_nears(): void
    {
        $contract = $this->contract([
            'notice_days' => 30,
            'expires_on' => now()->addDays(10)->toDateString(),
        ]);

        $this->sweep();
        $this->assertSame(1, $contract->tasks()->count());

        // Renewed to a date well outside the window: nothing new for now.
        app(ContractWatcher::class)->renew(
            $contract,
            $this->owner,
            CarbonImmutable::now(),
            CarbonImmutable::now()->addDays(200),
            null,
            null,
        );

        $this->assertSame(0, $this->sweep());

        // Time passes until the new expiry is inside its notice window.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(180));

        $this->assertSame(1, $this->sweep());
        $this->assertSame(2, $contract->refresh()->tasks()->count());
    }

    public function test_ending_a_contract_keeps_its_record_and_stops_the_chasing(): void
    {
        $contract = $this->contract([
            'expires_on' => now()->addDays(10)->toDateString(),
            'renewals' => 4,
        ]);

        $this->sweep();

        $this->actingAs($this->owner)->post(route('contracts.end', $contract))->assertRedirect();

        $contract->refresh();

        $this->assertSame(ContractStatus::Ended, $contract->status);
        $this->assertSame(4, $contract->renewals);
        $this->assertSame(TaskStatus::Done, $contract->tasks()->sole()->status);

        $this->assertSame(0, $this->sweep());
    }

    public function test_a_lapse_that_costs_money_is_said_louder_than_one_that_does_not(): void
    {
        $this->contract()->update(['kind' => ContractKind::Licence, 'expires_on' => now()->subDays(12)->toDateString()]);

        $this->contract([
            'kind' => ContractKind::Other,
            'title' => 'قرارداد لوازم‌التحریر',
            'expires_on' => now()->subDays(12)->toDateString(),
        ]);

        $this->actingAs($this->owner)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('منقضی شده که رها کردنش گران است');
    }

    public function test_a_term_that_ends_before_it_starts_is_refused(): void
    {
        // It would sit permanently expired, raise a renewal on every sweep,
        // and read as a bug in the engine rather than a typo in the form.
        $this->actingAs($this->owner)->post(route('contracts.store'), [
            'title' => 'قرارداد',
            'kind' => ContractKind::Employment->value,
            'party_type' => PartyType::Employee->value,
            'party_name' => 'رضا مرادی',
            'starts_date' => '1405/08/01',
            'expires_date' => '1405/07/01',
            'notice_days' => 60,
        ])->assertSessionHasErrors('expires_date');

        $this->assertSame(0, Contract::count());
    }

    public function test_an_unparseable_jalali_date_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('contracts.store'), [
            'title' => 'قرارداد',
            'kind' => ContractKind::Employment->value,
            'party_type' => PartyType::Employee->value,
            'party_name' => 'رضا مرادی',
            'starts_date' => '1405/01/01',
            'expires_date' => '1405/13/45',
            'notice_days' => 60,
        ])->assertSessionHasErrors('expires_date');

        $this->assertSame(0, Contract::count());
    }

    public function test_picking_a_member_fills_the_party_name_from_their_record(): void
    {
        $this->actingAs($this->owner)->post(route('contracts.store'), [
            'title' => 'قرارداد یک‌ساله',
            'kind' => ContractKind::Employment->value,
            'party_type' => PartyType::Employee->value,
            'party_user_id' => $this->member->id,
            'starts_date' => '1405/01/01',
            'expires_date' => '1405/12/29',
            'notice_days' => 60,
        ])->assertRedirect();

        $contract = Contract::sole();

        $this->assertSame($this->member->id, $contract->party_user_id);
        $this->assertSame('رضا مرادی', $contract->party_name);
    }

    public function test_a_contract_needs_a_party_one_way_or_the_other(): void
    {
        $this->actingAs($this->owner)->post(route('contracts.store'), [
            'title' => 'قرارداد',
            'kind' => ContractKind::Employment->value,
            'party_type' => PartyType::Employee->value,
            'starts_date' => '1405/01/01',
            'expires_date' => '1405/12/29',
            'notice_days' => 60,
        ])->assertSessionHasErrors('party_name');
    }

    public function test_an_unassigned_contract_falls_to_whoever_filed_it(): void
    {
        $contract = $this->contract(['owner_id' => null, 'expires_on' => now()->addDays(10)->toDateString()]);

        $this->sweep();

        $this->assertSame($this->owner->id, $contract->tasks()->sole()->assignee_id);
    }

    public function test_a_contract_from_another_workspace_is_a_404(): void
    {
        $other = Contract::factory()->create();

        $this->actingAs($this->owner)->get(route('contracts.show', $other))->assertNotFound();

        $this->actingAs($this->owner)
            ->post(route('contracts.end', $other))
            ->assertNotFound();
    }

    public function test_the_file_shows_every_term_it_has_run(): void
    {
        $contract = $this->contract(['expires_on' => now()->addDays(10)->toDateString()]);

        foreach ([['1405/08/01', '1406/02/01'], ['1406/02/02', '1406/12/29']] as [$from, $to]) {
            $this->actingAs($this->owner)->post(route('contracts.renew', $contract), [
                'starts_date' => $from,
                'expires_date' => $to,
            ])->assertRedirect();
        }

        $this->assertSame(2, $contract->refresh()->terms()->count());

        $this->actingAs($this->owner)
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('دوره‌های قبلی');
    }
}
