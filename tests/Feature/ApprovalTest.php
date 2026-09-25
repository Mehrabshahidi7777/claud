<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\ApprovalRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FollowUpRunner;
use App\Services\FollowUpScheduler;
use App\Sms\Drivers\FakeSmsDriver;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The approval workflow earns its place through one behaviour: an approved
 * leave has to reach the follow-up engine. A company that signs someone's leave
 * form and then sends them "چرا انجام نشد؟" on the second day of it has taught
 * its people to ignore the channel, and the engine is the product.
 */
class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    private Workspace $workspace;

    private User $owner;

    private User $manager;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config([
            'sms.patterns.approval_request.code' => 'P-APPROVAL-REQ',
            'sms.patterns.approval_decision.code' => 'P-APPROVAL-DEC',
            'sms.patterns.chase.code' => 'P-CHASE',
        ]);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');

        $this->workspace = Workspace::factory()->create();

        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی']);
        $this->manager = User::factory()->create(['name' => 'حسین نجفی']);
        $this->member = User::factory()->create(['name' => 'رضا مرادی']);

        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
        $this->workspace->members()->attach($this->manager, ['role' => 'admin']);
        $this->workspace->members()->attach($this->member, [
            'role' => 'member',
            'manager_id' => $this->manager->id,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_leave_request_is_routed_to_the_requester_own_manager(): void
    {
        $this->actingAs($this->member)
            ->post(route('approvals.store'), [
                'type' => ApprovalType::Leave->value,
                'title' => 'مرخصی استحقاقی',
                'starts_on' => '1405/07/15',
                'ends_on' => '1405/07/17',
            ])
            ->assertRedirect(route('approvals.index'));

        $request = ApprovalRequest::sole();

        $this->assertSame($this->manager->id, $request->approver_id);
        $this->assertSame(ApprovalStatus::Pending, $request->status);
        $this->assertSame(3, $request->dayCount());

        $this->sms->assertSent('approval_request', $this->manager->phone);
        $this->assertCount(1, $this->manager->unreadNotifications);

        // The owner is not the named approver, so it is not their problem.
        $this->sms->assertSentCount(1);
    }

    public function test_a_request_from_someone_with_no_named_manager_falls_to_the_owners(): void
    {
        // Small workspaces never fill the manager column in. A request that
        // waited for a person who does not exist would wait forever.
        $orphan = User::factory()->create(['name' => 'سارا کریمی']);
        $this->workspace->members()->attach($orphan, ['role' => 'member']);

        $this->actingAs($orphan)->post(route('approvals.store'), [
            'type' => ApprovalType::Purchase->value,
            'title' => 'خرید لپ‌تاپ',
            'amount' => 120_000_000,
        ])->assertRedirect();

        $request = ApprovalRequest::sole();

        $this->assertNull($request->approver_id);
        $this->assertSame(120_000_000, $request->amount);

        $this->sms->assertSent('approval_request', $this->owner->phone);
        $this->sms->assertSent('approval_request', $this->manager->phone);
    }

    public function test_approving_leave_stops_the_engine_chasing_that_person(): void
    {
        $request = ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $this->member->id,
            'approver_id' => $this->manager->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(4)->toDateString(),
        ]);

        $this->actingAs($this->manager)
            ->post(route('approvals.decide', $request), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame(ApprovalStatus::Approved, $request->refresh()->status);
        $this->assertSame($this->manager->id, $request->decided_by);

        $awayUntil = $this->workspace->members()
            ->where('users.id', $this->member->id)
            ->first()->pivot->away_until;

        $this->assertNotNull($awayUntil, 'Approved leave must write away_until onto the membership.');

        // The behaviour that matters: an overdue task belonging to the person
        // on approved leave produces no chase at all.
        $task = Task::factory()->for($this->workspace)->create([
            'assignee_id' => $this->member->id,
            'creator_id' => $this->manager->id,
            'due_at' => now()->subHours(3),
        ]);

        $this->sms->sent = [];

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(FollowUpRunner::class)->sweep();

        $this->sms->assertNotSent('chase');
        $this->assertSame('recipient_away', $task->followUps()->first()->skip_reason);
    }

    public function test_a_short_leave_never_shortens_leave_that_is_already_running(): void
    {
        // A month of sick leave already granted must not be cut to two days
        // because a second, shorter request was approved on top of it.
        $long = $this->approveLeave(now()->addDay(), now()->addDays(30));

        $this->assertTrue(
            $this->awayUntil()->greaterThan(now()->addDays(29)),
            'The long leave should have been written first.',
        );

        $this->approveLeave(now()->addDays(2), now()->addDays(3));

        $this->assertTrue($this->awayUntil()->greaterThan(now()->addDays(29)));
        $this->assertSame(ApprovalStatus::Approved, $long->refresh()->status);
    }

    public function test_leave_granted_past_an_existing_return_date_extends_it(): void
    {
        $this->approveLeave(now()->addDay(), now()->addDays(3));
        $this->approveLeave(now()->addDays(4), now()->addDays(9));

        $this->assertTrue($this->awayUntil()->greaterThan(now()->addDays(8)));
    }

    public function test_rejecting_tells_the_requester_why_and_changes_nothing_else(): void
    {
        $request = ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $this->member->id,
            'approver_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)->post(route('approvals.decide', $request), [
            'decision' => 'reject',
            'note' => 'این هفته پروژه جردن تحویل دارد.',
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame(ApprovalStatus::Rejected, $request->status);
        $this->assertSame('این هفته پروژه جردن تحویل دارد.', $request->decision_note);

        $this->sms->assertSent('approval_decision', $this->member->phone);

        $notification = $this->member->unreadNotifications()->sole();
        $this->assertStringContainsString('رد شد', $notification->data['message']);
        $this->assertStringContainsString('پروژه جردن', $notification->data['message']);

        $this->assertNull(
            $this->workspace->members()->where('users.id', $this->member->id)->first()->pivot->away_until,
        );
    }

    public function test_nobody_approves_their_own_request_whatever_their_role(): void
    {
        // An owner signing off their own leave is a record nobody believes,
        // and the audit trail is most of what this feature sells.
        $request = ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->post(route('approvals.decide', $request), ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertSame(ApprovalStatus::Pending, $request->refresh()->status);
    }

    public function test_an_ordinary_member_cannot_decide_someone_else_request(): void
    {
        $request = ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $this->owner->id,
            'approver_id' => $this->manager->id,
        ]);

        $this->actingAs($this->member)
            ->post(route('approvals.decide', $request), ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_a_request_from_another_workspace_is_invisible_rather_than_forbidden(): void
    {
        $other = ApprovalRequest::factory()->create();

        $this->actingAs($this->manager)
            ->post(route('approvals.decide', $other), ['decision' => 'approve'])
            ->assertNotFound();
    }

    public function test_leave_with_an_unusable_date_is_refused_rather_than_stored_without_one(): void
    {
        // The failure this guards against is silent: an unparseable date would
        // become null, the leave would be approved, away_until would never be
        // written, and the engine would keep chasing someone on leave.
        $this->actingAs($this->member)->post(route('approvals.store'), [
            'type' => ApprovalType::Leave->value,
            'title' => 'مرخصی',
            'starts_on' => '1405/13/45',
            'ends_on' => '1405/13/46',
        ])->assertSessionHasErrors('starts_on');

        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_a_leave_ending_before_it_starts_is_refused(): void
    {
        $this->actingAs($this->member)->post(route('approvals.store'), [
            'type' => ApprovalType::Leave->value,
            'title' => 'مرخصی',
            'starts_on' => '1405/07/17',
            'ends_on' => '1405/07/15',
        ])->assertSessionHasErrors('ends_on');

        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_the_approver_is_shown_what_falls_over_while_the_person_is_away(): void
    {
        // "مرخصی سه روزه" is not a decision anyone can make. "مرخصی سه روزه، و
        // این تسک سررسیدش وسط همان بازه است" is.
        $request = ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $this->member->id,
            'approver_id' => $this->manager->id,
            'starts_on' => now()->addDays(3)->toDateString(),
            'ends_on' => now()->addDays(5)->toDateString(),
        ]);

        Task::factory()->for($this->workspace)->create([
            'title' => 'تحویل گزارش سرویس‌ها',
            'assignee_id' => $this->member->id,
            'creator_id' => $this->manager->id,
            'due_at' => now()->addDays(4),
        ]);

        Task::factory()->for($this->workspace)->create([
            'title' => 'کار بعد از برگشت',
            'assignee_id' => $this->member->id,
            'creator_id' => $this->manager->id,
            'due_at' => now()->addDays(20),
        ]);

        $this->actingAs($this->manager)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('تحویل گزارش سرویس‌ها')
            ->assertDontSee('کار بعد از برگشت');
    }

    public function test_only_the_requester_may_withdraw_a_request(): void
    {
        $request = ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $this->member->id,
            'approver_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->post(route('approvals.cancel', $request))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->post(route('approvals.cancel', $request))
            ->assertRedirect();

        $this->assertSame(ApprovalStatus::Cancelled, $request->refresh()->status);

        // A withdrawal is not a decision, so nobody gets told by SMS.
        $this->sms->assertNotSent('approval_decision');
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $request = ApprovalRequest::factory()->for($this->workspace)->approved()->create([
            'requester_id' => $this->member->id,
            'approver_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->post(route('approvals.decide', $request), ['decision' => 'reject'])
            ->assertForbidden();

        $this->assertSame(ApprovalStatus::Approved, $request->refresh()->status);
    }

    public function test_approving_a_purchase_leaves_the_engine_alone(): void
    {
        $request = ApprovalRequest::factory()->for($this->workspace)->purchase()->create([
            'requester_id' => $this->member->id,
            'approver_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->post(route('approvals.decide', $request), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertNull(
            $this->workspace->members()->where('users.id', $this->member->id)->first()->pivot->away_until,
        );
    }

    private function approveLeave(DateTimeInterface $startsOn, DateTimeInterface $endsOn): ApprovalRequest
    {
        $request = ApprovalRequest::factory()->for($this->workspace)->create([
            'requester_id' => $this->member->id,
            'approver_id' => $this->manager->id,
            'starts_on' => $startsOn->format('Y-m-d'),
            'ends_on' => $endsOn->format('Y-m-d'),
        ]);

        $this->actingAs($this->manager)
            ->post(route('approvals.decide', $request), ['decision' => 'approve'])
            ->assertRedirect();

        return $request;
    }

    private function awayUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->workspace->members()->where('users.id', $this->member->id)->first()->pivot->away_until,
        );
    }

    public function test_both_approval_templates_fit_a_single_persian_message(): void
    {
        // Persian is UCS-2: seventy characters, then the bill doubles. Every
        // template is written to fit at its longest realistic values.
        $request = PatternMessageFactory::approvalRequest();
        $decision = PatternMessageFactory::approvalDecision();

        $this->assertSame(1, PersianText::segments($request));
        $this->assertSame(1, PersianText::segments($decision));
    }
}

/**
 * Renders the two approval templates at their longest realistic values, which
 * is what the segment count has to hold for.
 */
final class PatternMessageFactory
{
    public static function approvalRequest(): string
    {
        return str_replace(
            ['%type%', '%name%', '%title%'],
            ['مرخصی', 'عبدالرضا', PersianText::truncate(str_repeat('ط', 40), 20)],
            (string) config('sms.patterns.approval_request.preview'),
        );
    }

    public static function approvalDecision(): string
    {
        return str_replace(
            ['%result%', '%title%'],
            ['تأیید', PersianText::truncate(str_repeat('ط', 40))],
            (string) config('sms.patterns.approval_decision.preview'),
        );
    }
}
