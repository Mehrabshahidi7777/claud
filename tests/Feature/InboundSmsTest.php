<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Enums\InboundIntent;
use App\Enums\TaskStatus;
use App\Models\SmsInbound;
use App\Models\Task;
use App\Models\TaskFollowUp;
use App\Models\User;
use App\Models\Workspace;
use App\Services\InboundProcessor;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundSmsTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config([
            'sms.patterns.confirm_done.code' => 'P-DONE',
            'sms.patterns.confirm_defer.code' => 'P-DEFER',
            'sms.patterns.defer_ask.code' => 'P-ASK',
            'sms.patterns.unknown.code' => 'P-UNKNOWN',
            'sms.amoot.inbound_secret' => 'test-secret',
        ]);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_replying_one_closes_the_task(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, '1', 'msg-1');

        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->sms->assertSent('confirm_done');
    }

    public function test_a_persian_digit_one_closes_it_just_the_same(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, '۱', 'msg-1');

        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
    }

    public function test_the_words_people_actually_write_close_it_too(): void
    {
        foreach (['انجام شد', 'اوکی', 'بله', 'تمام'] as $index => $reply) {
            [$task, $user] = $this->chasedTask();

            app(InboundProcessor::class)->process($user->phone, $reply, "msg-word-$index");

            $this->assertSame(
                TaskStatus::Done,
                $task->fresh()->status,
                "Reply [$reply] should have closed the task.",
            );
        }
    }

    public function test_closing_a_task_clears_what_the_ladder_still_had_pending(): void
    {
        [$task, $user] = $this->chasedTask();

        TaskFollowUp::create([
            'task_id' => $task->id,
            'step' => FollowUpStep::Escalate->value,
            'channel' => 'sms',
            'recipient_id' => $user->id,
            'scheduled_at' => now()->addHours(4),
            'status' => FollowUpStatus::Pending,
        ]);

        app(InboundProcessor::class)->process($user->phone, '1', 'msg-1');

        $this->assertSame(
            0,
            $task->followUps()->where('status', FollowUpStatus::Pending->value)->count(),
        );
    }

    public function test_replying_two_asks_for_a_date_and_does_not_defer_yet(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, '2', 'msg-1');

        $this->sms->assertSent('defer_ask');

        // A task never enters `deferred` without a new date.
        $this->assertNotSame(TaskStatus::Deferred, $task->fresh()->status);
    }

    public function test_the_example_date_in_the_question_is_itself_a_valid_answer(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, '2', 'msg-1');

        $question = collect($this->sms->sent)->last(fn ($message) => $message->key === 'defer_ask');

        // Someone who copies the example exactly must not be told "متوجه نشدم".
        app(InboundProcessor::class)->process($user->phone, $question->tokens['example'], 'msg-2');

        $this->assertSame(TaskStatus::Deferred, $task->fresh()->status);
    }

    public function test_sending_a_jalali_date_defers_the_task_to_it(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, '1404/07/15', 'msg-1');

        $fresh = $task->fresh();

        $this->assertSame(TaskStatus::Deferred, $fresh->status);
        $this->assertSame('2025-10-07', $fresh->due_at->toDateString());
        $this->assertSame(1, $fresh->defer_count);
        $this->sms->assertSent('confirm_defer');
    }

    public function test_it_accepts_the_separators_and_digits_people_type(): void
    {
        foreach (['1404-07-15', '۱۴۰۴/۰۷/۱۵', '1404.07.15'] as $index => $written) {
            [$task, $user] = $this->chasedTask();

            app(InboundProcessor::class)->process($user->phone, $written, "msg-date-$index");

            $this->assertSame(
                '2025-10-07',
                $task->fresh()->due_at->toDateString(),
                "Date written as [$written] should have been understood.",
            );
        }
    }

    public function test_the_word_tomorrow_works_as_a_date(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, 'فردا', 'msg-1');

        $this->assertSame(
            CarbonImmutable::tomorrow()->toDateString(),
            $task->fresh()->due_at->toDateString(),
        );
    }

    public function test_an_impossible_date_is_queried_rather_than_guessed_at(): void
    {
        // Esfand has 29 days in a common year, so 1404/12/30 does not exist.
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, '1404/12/30', 'msg-1');

        $this->sms->assertSent('unknown');
        $this->assertNotSame(TaskStatus::Deferred, $task->fresh()->status);
    }

    public function test_something_unrecognisable_earns_exactly_one_clarification(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, 'سلام خوبی؟', 'msg-1');

        $this->sms->assertSent('unknown');
        $this->assertSame(InboundIntent::Unknown, SmsInbound::first()->interpreted_as);
    }

    public function test_a_keyword_must_be_the_whole_reply_not_a_fragment_of_it(): void
    {
        // Substring matching would read this as a deferral the sender never meant.
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, 'کار ۲ روز دیگه تمام میشود', 'msg-1');

        $this->assertSame(InboundIntent::Unknown, SmsInbound::first()->interpreted_as);
        $this->assertNotSame(TaskStatus::Deferred, $task->fresh()->status);
    }

    public function test_a_stranger_gets_no_reply_at_all(): void
    {
        // Answering unknown numbers is a way to spend our credit on someone
        // else's behalf.
        app(InboundProcessor::class)->process('09129999999', '1', 'msg-1');

        $this->sms->assertNothingSent();
        $this->assertSame('unknown_sender', SmsInbound::first()->ignored_reason);
    }

    public function test_a_reply_arriving_after_the_window_closes_changes_nothing(): void
    {
        [$task, $user] = $this->chasedTask(chasedAt: now()->subHours(30));

        app(InboundProcessor::class)->process($user->phone, '1', 'msg-1');

        $this->assertSame(TaskStatus::Chased, $task->fresh()->status);
        $this->assertSame('no_open_chase', SmsInbound::first()->ignored_reason);
    }

    public function test_replying_cancel_raises_it_with_the_manager_instead_of_acting(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, 'لغو', 'msg-1');

        $this->assertNotSame(TaskStatus::Cancelled, $task->fresh()->status);
        $this->assertSame('cancellation_needs_manager', SmsInbound::first()->ignored_reason);
        $this->assertDatabaseHas('activities', ['event' => 'task.cancellation_requested']);
    }

    public function test_replying_stop_is_honoured_immediately_and_reported(): void
    {
        [$task, $user] = $this->chasedTask();

        app(InboundProcessor::class)->process($user->phone, 'قطع', 'msg-1');

        $this->assertNotNull($user->fresh()->sms_opted_out_at);
        $this->assertDatabaseHas('activities', ['event' => 'user.sms_opted_out']);
    }

    public function test_a_number_in_any_written_form_reaches_the_right_person(): void
    {
        [$task, $user] = $this->chasedTask(phone: '989121234567');

        // The provider may report the sender in any of these shapes.
        app(InboundProcessor::class)->process('+989121234567', '1', 'msg-1');

        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
    }

    public function test_the_webhook_refuses_a_request_without_the_secret(): void
    {
        $this->postJson('/api/webhooks/sms/amoot/anything', [
            'From' => '09121234567',
            'Text' => '1',
            'MessageID' => 'x-1',
        ])->assertForbidden();
    }

    public function test_the_webhook_accepts_the_secret_and_queues_the_work(): void
    {
        [$task, $user] = $this->chasedTask();

        $this->postJson('/api/webhooks/sms/amoot/abc123', [
            'From' => $user->localPhone(),
            'Text' => '1',
            'MessageID' => 'x-1',
            'secret' => 'test-secret',
        ])->assertOk()->assertJson(['ok' => true]);

        // The queue runs synchronously under test, so the effect is visible.
        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
    }

    public function test_the_webhook_rejects_a_payload_missing_its_fields(): void
    {
        $this->postJson('/api/webhooks/sms/amoot/abc123', [
            'secret' => 'test-secret',
        ])->assertStatus(422);
    }

    public function test_a_redelivered_message_is_handled_once(): void
    {
        [$task, $user] = $this->chasedTask();

        $payload = [
            'From' => $user->localPhone(),
            'Text' => '1',
            'MessageID' => 'duplicate-1',
            'secret' => 'test-secret',
        ];

        $this->postJson('/api/webhooks/sms/amoot/abc123', $payload)->assertOk();
        $this->postJson('/api/webhooks/sms/amoot/abc123', $payload)->assertOk();

        $this->assertSame(1, SmsInbound::where('provider_message_id', 'duplicate-1')->count());
        $this->sms->assertSentCount(1);
    }

    /**
     * A task that has already had its chase sent, which is the only state an
     * inbound reply can be matched against.
     *
     * @return array{0: Task, 1: User}
     */
    private function chasedTask(?\DateTimeInterface $chasedAt = null, ?string $phone = null): array
    {
        $workspace = Workspace::factory()->create();

        $user = $phone === null
            ? User::factory()->create()
            : User::factory()->withPhone($phone)->create();

        $workspace->members()->attach($user, ['role' => 'member']);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $user->id,
            'creator_id' => $user->id,
            'due_at' => now()->subHours(3),
            'status' => TaskStatus::Chased,
        ]);

        TaskFollowUp::create([
            'task_id' => $task->id,
            'step' => FollowUpStep::Chase->value,
            'channel' => 'sms',
            'recipient_id' => $user->id,
            'scheduled_at' => $chasedAt ?? now()->subHour(),
            'sent_at' => $chasedAt ?? now()->subHour(),
            'status' => FollowUpStatus::Sent,
        ]);

        return [$task, $user];
    }
}
