<?php

namespace App\Services;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Enums\InboundIntent;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\SmsInbound;
use App\Models\Task;
use App\Models\User;
use App\Sms\PatternMessage;
use App\Support\JalaliDate;
use App\Support\PersianText;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Applies an inbound reply to the task it belongs to.
 *
 * The reply carries no task id — deliberately, since an id eats characters and
 * people mistype it. The task is inferred instead: the most recent chase sent
 * to that number that is still open and still inside the reply window.
 */
class InboundProcessor
{
    public function __construct(
        private readonly SmsDriver $driver,
        private readonly InboundInterpreter $interpreter,
        private readonly FollowUpScheduler $scheduler,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public function process(string $fromPhone, string $body, string $providerMessageId, array $raw = []): SmsInbound
    {
        $phone = PhoneNumber::normalize($fromPhone) ?? $fromPhone;

        // Stored raw first, interpreted second. When the matching rules turn
        // out to be wrong — and in the first months they will be — this is
        // what lets them be replayed against real traffic.
        $record = SmsInbound::create([
            'from_phone' => $phone,
            'body' => $body,
            'normalized_body' => PersianText::normalize($body),
            'raw' => $raw ?: null,
            'provider_message_id' => $providerMessageId,
            'received_at' => now(),
        ]);

        $user = User::where('phone', $phone)->first();

        // A message from a number belonging to nobody gets no reply at all.
        // Answering strangers turns a dedicated line into a way to spend our
        // credit on someone else's behalf.
        if ($user === null) {
            return tap($record)->update(['ignored_reason' => 'unknown_sender']);
        }

        $record->update(['user_id' => $user->id]);

        $reading = $this->interpreter->interpret($body);
        $record->update(['interpreted_as' => $reading['intent']]);

        $task = $this->matchTask($user);

        if ($task === null) {
            return tap($record)->update(['ignored_reason' => 'no_open_chase']);
        }

        $record->update(['matched_task_id' => $task->id]);

        return $this->apply($record, $reading['intent'], $reading['date'], $task, $user);
    }

    /**
     * The last chase sent to this person that is still answerable. A reply
     * arriving after the window closes is recorded but changes nothing.
     */
    private function matchTask(User $user): ?Task
    {
        $window = CarbonImmutable::now()->subHours((int) config('followup.reply_window_hours', 24));

        return Task::query()
            ->chaseable()
            ->where('assignee_id', $user->id)
            ->whereHas('followUps', function ($query) use ($window) {
                $query->where('step', FollowUpStep::Chase->value)
                    ->where('status', FollowUpStatus::Sent->value)
                    ->where('sent_at', '>=', $window);
            })
            ->latest('due_at')
            ->first();
    }

    private function apply(
        SmsInbound $record,
        InboundIntent $intent,
        ?CarbonImmutable $date,
        Task $task,
        User $user,
    ): SmsInbound {
        return match ($intent) {
            InboundIntent::Done => $this->markDone($record, $task, $user),
            InboundIntent::Defer => $this->askForDate($record, $user),
            InboundIntent::NewDate => $this->acceptNewDate($record, $task, $user, $date),
            InboundIntent::Cancel => $this->raiseCancellation($record, $task, $user),
            InboundIntent::OptOut => $this->optOut($record, $user, $task),
            InboundIntent::Unknown => $this->clarify($record, $user),
        };
    }

    private function markDone(SmsInbound $record, Task $task, User $user): SmsInbound
    {
        $task->update([
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);

        // The ladder is over. Anything still pending would be chasing work
        // that is already finished.
        $task->followUps()->where('status', FollowUpStatus::Pending->value)->delete();

        $this->reply($user, 'confirm_done', ['name' => $user->firstName()]);

        Activity::record($task, 'task.completed_by_sms', $task->workspace_id, $user->id);

        return tap($record)->update(['applied' => true]);
    }

    private function askForDate(SmsInbound $record, User $user): SmsInbound
    {
        $this->reply($user, 'defer_ask');

        // Not applied yet: the deferral only counts once a date arrives. A
        // task never enters `deferred` without one.
        return tap($record)->update(['ignored_reason' => 'awaiting_new_date']);
    }

    private function acceptNewDate(SmsInbound $record, Task $task, User $user, ?CarbonImmutable $date): SmsInbound
    {
        if ($date === null) {
            return $this->clarify($record, $user);
        }

        $task->increment('defer_count');
        $task->update([
            'status' => TaskStatus::Deferred,
            'due_at' => $date->setTime(17, 0),
            'defer_reason' => $record->normalized_body,
        ]);

        // A deadline that moves rebuilds the ladder; rungs already fired stay
        // as history, unsent ones are rewritten against the new date.
        $this->scheduler->scheduleFor($task->fresh());

        $this->reply($user, 'confirm_defer', ['date' => JalaliDate::format($date)]);

        // A second deferral stops being an excuse and becomes a signal, so it
        // reaches the manager regardless of where the ladder stands.
        if ($task->defer_count >= (int) config('followup.defer_count_before_escalation', 2)) {
            Activity::record($task, 'task.repeatedly_deferred', $task->workspace_id, $user->id, [
                'defer_count' => $task->defer_count,
            ]);
        }

        return tap($record)->update(['applied' => true]);
    }

    /**
     * Cancelling is a manager's call. The field worker's request is logged and
     * raised, never acted on directly.
     */
    private function raiseCancellation(SmsInbound $record, Task $task, User $user): SmsInbound
    {
        Activity::record($task, 'task.cancellation_requested', $task->workspace_id, $user->id, [
            'via' => 'sms',
        ]);

        return tap($record)->update(['ignored_reason' => 'cancellation_needs_manager']);
    }

    private function optOut(SmsInbound $record, User $user, Task $task): SmsInbound
    {
        $user->update(['sms_opted_out_at' => now()]);

        // Honoured immediately, and reported: a manager needs to know that one
        // of their people has gone dark on the channel.
        Activity::record($user, 'user.sms_opted_out', $task->workspace_id, $user->id);

        return tap($record)->update(['applied' => true]);
    }

    private function clarify(SmsInbound $record, User $user): SmsInbound
    {
        $this->reply($user, 'unknown');

        return tap($record)->update(['ignored_reason' => 'not_understood']);
    }

    /**
     * @param  array<string, string|int>  $tokens
     */
    private function reply(User $user, string $patternKey, array $tokens = []): void
    {
        if ($user->hasOptedOutOfSms()) {
            return;
        }

        $this->driver->send(PatternMessage::make($user->phone, $patternKey, $tokens));
    }
}
