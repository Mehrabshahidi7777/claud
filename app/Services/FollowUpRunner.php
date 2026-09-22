<?php

namespace App\Services;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStep;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\SmsOutbound;
use App\Models\TaskFollowUp;
use App\Notifications\TaskReminder;
use App\Sms\PatternMessage;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fires one rung of the ladder. Everything that decides whether a message goes
 * out lives in SendGate; this class does the sending, the bookkeeping, and the
 * scheduling of whatever comes next.
 */
class FollowUpRunner
{
    public function __construct(
        private readonly SmsDriver $driver,
        private readonly SendGate $gate,
        private readonly FollowUpScheduler $scheduler,
    ) {}

    public function run(TaskFollowUp $followUp): void
    {
        if (! $followUp->status->isActionable()) {
            return;
        }

        $verdict = $this->gate->evaluate($followUp);

        match ($verdict['verdict']) {
            SendGate::SKIP => $followUp->markSkipped($verdict['reason']),
            SendGate::DEFER => $followUp->deferTo($verdict['retry_at']),
            default => $this->dispatch($followUp),
        };
    }

    private function dispatch(TaskFollowUp $followUp): void
    {
        $patternKey = $followUp->step->patternKey();

        // The free rungs. They reach the assignee before anything has gone
        // wrong, which is what keeps the SMS rungs rare enough to still be
        // taken seriously when they do fire.
        if ($patternKey === null) {
            $followUp->recipient?->notify(new TaskReminder($followUp->task, $followUp->step));

            $this->advanceStatus($followUp);
            $followUp->markSent();

            return;
        }

        $message = PatternMessage::make(
            phone: $followUp->recipient->phone,
            key: $patternKey,
            tokens: $this->tokensFor($followUp, $patternKey),
        );

        $ledger = $this->openLedgerEntry($followUp, $message);

        $result = $this->driver->send($message);

        if (! $result->successful) {
            $ledger->update(['status' => 'failed', 'error' => $result->error]);
            $followUp->markFailed($result->error ?? 'send failed');

            return;
        }

        // Credit is only consumed once the provider has accepted the message,
        // and counted in parts because parts are what it bills.
        DB::transaction(function () use ($ledger, $followUp, $message, $result) {
            $ledger->update([
                'status' => 'sent',
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
            ]);

            $followUp->task->workspace->consumeSmsCredit($message->segments());
            $followUp->markSent();
            $this->advanceStatus($followUp);
        });

        // The next rung is measured from when this one actually went out, not
        // from the deadline — a chase delayed overnight must not drag its
        // escalation into the small hours behind it.
        if ($followUp->step === FollowUpStep::Chase) {
            $this->scheduler->scheduleEscalation($followUp);
        }

        Activity::record(
            subject: $followUp->task,
            event: 'follow_up.'.$followUp->step->name,
            workspaceId: $followUp->task->workspace_id,
            properties: [
                'step' => $followUp->step->value,
                'to' => $followUp->recipient_id,
                'segments' => $message->segments(),
            ],
        );
    }

    private function openLedgerEntry(TaskFollowUp $followUp, PatternMessage $message): SmsOutbound
    {
        return SmsOutbound::create([
            'workspace_id' => $followUp->task->workspace_id,
            'user_id' => $followUp->recipient_id,
            'task_follow_up_id' => $followUp->id,
            'phone' => $message->phone,
            'pattern_key' => $message->key,
            'pattern_code' => $message->code,
            'tokens' => $message->tokens,
            'rendered_preview' => $message->preview,
            'segments' => $message->segments(),
            'status' => 'queued',
        ]);
    }

    /**
     * @return array<string, string|int>
     */
    private function tokensFor(TaskFollowUp $followUp, string $patternKey): array
    {
        $task = $followUp->task;

        return match ($patternKey) {
            'chase' => [
                'name' => $task->responsible()->firstName(),
                // A long title is the fastest way to double the bill on every
                // chase, so it is cut to what one message can carry.
                'title' => PersianText::truncate($task->title),
            ],
            'escalate' => [
                'title' => PersianText::truncate($task->title),
                'name' => $task->responsible()->firstName(),
                'hours' => $task->hoursOverdue(),
            ],
            default => [],
        };
    }

    private function advanceStatus(TaskFollowUp $followUp): void
    {
        $task = $followUp->task;

        $next = match ($followUp->step) {
            FollowUpStep::Nudge => TaskStatus::Nudged,
            FollowUpStep::DueMorning => TaskStatus::Due,
            FollowUpStep::Chase => TaskStatus::Chased,
            FollowUpStep::Escalate => TaskStatus::Escalated,
            FollowUpStep::WeeklyReport => null,
        };

        // Never walk a task backwards: a reply that arrived between the gate
        // and the send has the final say.
        if ($next !== null && ! $task->status->isClosed()) {
            $task->update(['status' => $next]);
        }
    }

    /**
     * The sweep. Runs every five minutes and treats anything already due as
     * due now — minute-level precision buys nothing and multiplies load.
     *
     * @return int number of rungs processed
     */
    public function sweep(?CarbonImmutable $now = null): int
    {
        $processed = 0;

        TaskFollowUp::query()
            ->due()
            ->with(['task.workspace', 'task.assignee', 'task.creator', 'recipient'])
            ->orderBy('scheduled_at')
            ->chunkById(100, function ($followUps) use (&$processed) {
                foreach ($followUps as $followUp) {
                    $this->run($followUp);
                    $processed++;
                }
            });

        return $processed;
    }
}
