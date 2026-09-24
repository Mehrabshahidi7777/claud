<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Contract;
use App\Models\ContractTerm;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Raises the renewal before a contract or licence lapses, and records the new
 * term once it is renewed.
 *
 * The notice window is the whole product here. A reminder on the day a staff
 * contract expires is worth nothing — by then the company is already carrying
 * a liability it does not know about. A contractor qualification that lapses
 * quietly disqualifies the company from tenders it has already spent money
 * bidding for, and nobody finds out until the bid is rejected.
 */
class ContractWatcher
{
    public function __construct(private readonly FollowUpScheduler $scheduler) {}

    public function sweep(): int
    {
        $raised = 0;

        foreach (Workspace::all() as $workspace) {
            $raised += $this->sweepWorkspace($workspace);
        }

        return $raised;
    }

    public function sweepWorkspace(Workspace $workspace): int
    {
        $due = Contract::forWorkspace($workspace->id)
            ->active()
            ->with(['owner', 'party'])
            ->get()
            ->filter(fn (Contract $contract) => $contract->hasExpired() || $contract->isExpiringSoon());

        $raised = 0;

        foreach ($due as $contract) {
            if ($this->raiseRenewal($contract)) {
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * Record a renewal: the term in force is pushed into history and a new
     * one takes its place.
     *
     * The new term's length is whatever was agreed rather than a fixed step —
     * a staff contract renewed for six months this time and a year the next
     * is the ordinary case, not the exception.
     */
    public function renew(
        Contract $contract,
        User $actor,
        CarbonImmutable $startsOn,
        CarbonImmutable $expiresOn,
        ?int $value,
        ?string $note,
    ): void {
        DB::transaction(function () use ($contract, $actor, $startsOn, $expiresOn, $value, $note) {
            ContractTerm::create([
                'contract_id' => $contract->id,
                'recorded_by' => $actor->id,
                'starts_on' => $contract->starts_on->toDateString(),
                'expires_on' => $contract->expires_on->toDateString(),
                'value' => $contract->value,
                'note' => $note,
            ]);

            $contract->update([
                'starts_on' => $startsOn->toDateString(),
                'expires_on' => $expiresOn->toDateString(),
                'value' => $value ?? $contract->value,
                'renewals' => $contract->renewals + 1,
            ]);

            $this->closeOpenRenewalTasks($contract);
        });

        Activity::record($contract, 'contract.renewed', $contract->workspace_id, $actor->id, [
            'expires_on' => $expiresOn->toDateString(),
        ]);
    }

    /**
     * Close a contract for good. Not a delete: how long it ran and what it
     * was worth is exactly what somebody looks up a year later.
     */
    public function end(Contract $contract, User $actor): void
    {
        DB::transaction(function () use ($contract) {
            $contract->update([
                'status' => ContractStatus::Ended,
                'ended_on' => now()->toDateString(),
            ]);

            $this->closeOpenRenewalTasks($contract);
        });

        Activity::record($contract, 'contract.ended', $contract->workspace_id, $actor->id);
    }

    /**
     * One open renewal task per contract. Without this the sweep raises a
     * fresh one every night through the whole notice window — sixty tasks for
     * one staff contract, and a recipient who stops reading any of them.
     */
    private function raiseRenewal(Contract $contract): bool
    {
        $alreadyOpen = $contract->tasks()
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->exists();

        if ($alreadyOpen) {
            return false;
        }

        $assignee = $contract->owner_id ?? $contract->created_by;

        $task = Task::create([
            'workspace_id' => $contract->workspace_id,
            'contract_id' => $contract->id,
            'title' => sprintf(
                '%s: %s — %s',
                $contract->auto_renews ? 'تصمیم قبل از تمدید خودکار' : 'تمدید',
                PersianText::truncate($contract->title, 30),
                PersianText::truncate($contract->party_name, 25),
            ),
            'description' => $this->describe($contract),
            'assignee_id' => $assignee,
            'creator_id' => $contract->created_by,

            // The deadline is the expiry itself, so the ladder's own urgency
            // tracks the real one. An already-lapsed contract is overdue from
            // the moment its task exists, which is the truth.
            'due_at' => CarbonImmutable::parse($contract->expires_on)->setTime(17, 0),

            // A lapse that carries legal or commercial exposure is not a
            // normal-priority reminder.
            'priority' => $contract->kind->lapseIsSerious()
                ? TaskPriority::High
                : TaskPriority::Normal,

            'status' => TaskStatus::Open,
        ]);

        $this->scheduler->scheduleFor($task);

        Activity::record($contract, 'contract.renewal_raised', $contract->workspace_id, null, [
            'task_id' => $task->id,
            'days_to_expiry' => $contract->daysToExpiry(),
        ]);

        return true;
    }

    private function closeOpenRenewalTasks(Contract $contract): void
    {
        $open = $contract->tasks()
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->get();

        foreach ($open as $task) {
            $task->update(['status' => TaskStatus::Done, 'completed_at' => now()]);
            $task->followUps()->where('status', 'pending')->delete();
        }
    }

    private function describe(Contract $contract): string
    {
        $days = $contract->daysToExpiry();

        $lines = [
            $contract->kind->label().' — '.$contract->party_type->label().': '.$contract->party_name,
            $days < 0
                ? sprintf('از انقضا %s روز گذشته است.', abs($days))
                : sprintf('%s روز تا انقضا.', $days),
        ];

        if ($contract->reference !== null) {
            $lines[] = 'شماره: '.$contract->reference;
        }

        if ($contract->value !== null) {
            $lines[] = 'مبلغ دوره جاری: '.number_format($contract->value).' ریال';
        }

        if ($contract->auto_renews) {
            $lines[] = 'این قرارداد خودبه‌خود تمدید می‌شود — اگر نمی‌خواهید، قبل از انقضا اقدام کنید.';
        }

        if ($contract->note !== null) {
            $lines[] = $contract->note;
        }

        return implode("\n", $lines);
    }
}
