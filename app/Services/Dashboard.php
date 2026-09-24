<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\FollowUpStatus;
use App\Enums\TaskStatus;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\Receivable;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskFollowUp;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The one screen that answers "چه خبر؟" without anyone clicking.
 *
 * Composed per workspace type rather than per feature: a household opening
 * this must not be shown an empty receivables tile, because an empty tile
 * reads as a broken feature rather than as one they do not have.
 *
 * Everything here is a figure somebody can act on today. Counts that only
 * describe the database — total tasks ever, members registered — are left
 * out on purpose; they make a dashboard look busy and give nobody anything
 * to do.
 */
class Dashboard
{
    /**
     * @return array<string, mixed>
     */
    public function for(Workspace $workspace, User $user): array
    {
        $cards = [
            'mine' => $this->myWork($workspace, $user),
            'attention' => $this->needsAttention($workspace),
            'nextMoves' => $this->nextMoves($workspace),
        ];

        if ($workspace->has('recurring')) {
            $cards['recurring'] = $this->recurring($workspace);
        }

        if ($workspace->has('contracts')) {
            $cards['contracts'] = $this->contracts($workspace);
        }

        if ($workspace->has('finance')) {
            $cards['money'] = $this->money($workspace);
        }

        if ($workspace->has('approvals')) {
            $cards['approvals'] = $this->approvals($workspace, $user);
        }

        if ($workspace->has('settlements')) {
            $cards['settlements'] = $this->settlements($workspace, $user);
        }

        return $cards;
    }

    /**
     * What this person owes, soonest first. The dashboard opens on the
     * viewer's own work rather than on the company's totals: a manager sees
     * their list, and so does the technician who only ever gets texted.
     *
     * @return array{overdue: int, today: int, tasks: Collection<int, Task>}
     */
    private function myWork(Workspace $workspace, User $user): array
    {
        $open = Task::forWorkspace($workspace->id)
            ->chaseable()
            ->where('assignee_id', $user->id)
            ->with('assignee')
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->get();

        return [
            'overdue' => $open->filter->isOverdue()->count(),
            'today' => $open->filter(
                fn (Task $task) => $task->due_at !== null && $task->due_at->isToday(),
            )->count(),
            'tasks' => $open->take(6),
        ];
    }

    /**
     * Work that has gone wrong rather than work that exists: overdue, and
     * deferred more than once, which the design notes call a signal rather
     * than an excuse.
     *
     * @return array{overdue: Collection<int, Task>, unassigned: int, repeatedlyDeferred: int}
     */
    private function needsAttention(Workspace $workspace): array
    {
        $chaseable = Task::forWorkspace($workspace->id)
            ->chaseable()
            ->with('assignee')
            ->get();

        return [
            'overdue' => $chaseable->filter->isOverdue()
                ->sortBy('due_at')
                ->take(6)
                ->values(),

            // A task nobody owns is chased to whoever created it, which is
            // usually the person who least needs reminding.
            'unassigned' => $chaseable->whereNull('assignee_id')->count(),

            'repeatedlyDeferred' => Task::forWorkspace($workspace->id)
                ->chaseable()
                ->where('defer_count', '>=', (int) config('followup.defer_count_before_escalation', 2))
                ->count(),
        ];
    }

    /**
     * What the engine will do next, in order.
     *
     * This is the card that makes the product legible in thirty seconds: a
     * manager can see that things are going to happen without them, which is
     * the entire proposition stated as a list.
     *
     * @return Collection<int, TaskFollowUp>
     */
    private function nextMoves(Workspace $workspace): Collection
    {
        return TaskFollowUp::query()
            ->where('status', FollowUpStatus::Pending->value)
            ->whereHas('task', fn ($query) => $query->where('workspace_id', $workspace->id)->chaseable())
            ->with(['task', 'recipient'])
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return array{dueSoon: Collection<int, RecurringTask>, overdue: int, valueAtRisk: int}
     */
    private function recurring(Workspace $workspace): array
    {
        $active = RecurringTask::forWorkspace($workspace->id)->active()->get();

        return [
            'dueSoon' => $active
                ->filter->isDueToRaise()
                ->sortBy('next_due_on')
                ->take(5)
                ->values(),
            'overdue' => $active->filter->isOverdue()->count(),
            'valueAtRisk' => (int) $active->filter->isOverdue()->sum('estimated_value'),
        ];
    }

    /**
     * @return array{expiring: Collection<int, Contract>, expired: int, serious: int}
     */
    private function contracts(Workspace $workspace): array
    {
        $active = Contract::forWorkspace($workspace->id)
            ->where('status', ContractStatus::Active->value)
            ->get();

        $expired = $active->filter->hasExpired();

        return [
            'expiring' => $active
                ->filter(fn (Contract $c) => $c->hasExpired() || $c->isExpiringSoon())
                ->sortBy('expires_on')
                ->take(5)
                ->values(),
            'expired' => $expired->count(),
            'serious' => $expired->filter(fn (Contract $c) => $c->kind->lapseIsSerious())->count(),
        ];
    }

    /**
     * @return array{outstanding: int, overdue: int, unchased: int}
     */
    private function money(Workspace $workspace): array
    {
        $open = Receivable::forWorkspace($workspace->id)->outstanding()->get();

        return [
            'outstanding' => $open->sum(fn (Receivable $r) => $r->outstanding()),
            'overdue' => $open->filter->isOverdue()->sum(fn (Receivable $r) => $r->outstanding()),

            // Overdue with no task behind it: money nobody has been asked
            // about, which is the gap the whole finance module exists to close.
            'unchased' => $open->filter(
                fn (Receivable $r) => $r->isOverdue() && $r->task_id === null,
            )->count(),
        ];
    }

    /**
     * @return array{waitingOnMe: int, mine: int}
     */
    private function approvals(Workspace $workspace, User $user): array
    {
        $pending = ApprovalRequest::forWorkspace($workspace->id)->pending()->get();

        return [
            'waitingOnMe' => $pending
                ->filter(fn (ApprovalRequest $r) => $r->requester_id !== $user->id)
                ->count(),
            'mine' => $pending->where('requester_id', $user->id)->count(),
        ];
    }

    /**
     * Where this person stands with the group, and how far the whole group
     * is from being square.
     *
     * The viewer's own figure first, because it is the only one most people
     * read — and the transfer count second, because "two payments and it is
     * over" is what actually makes somebody pay.
     *
     * @return array{net: int, transfers: int, owed_by_me: int}
     */
    private function settlements(Workspace $workspace, User $user): array
    {
        $sheet = app(BalanceSheet::class);

        $net = collect($sheet->balances($workspace))
            ->firstWhere('user.id', $user->id)['net'] ?? 0;

        return [
            'net' => $net,
            'transfers' => count($sheet->transfers($workspace)),
            'owed_by_me' => max(0, -$net),
        ];
    }

    /**
     * Last seven days, as one honest figure.
     *
     * Counted on tasks that fell due in the window rather than on tasks
     * closed in it — closing an old task today would otherwise push this
     * week's rate above a hundred per cent.
     *
     * @return array{rate: ?int, closed: int, due: int}
     */
    public function weekSoFar(Workspace $workspace): array
    {
        $from = CarbonImmutable::now()->subDays(7);

        $due = Task::forWorkspace($workspace->id)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$from, CarbonImmutable::now()])
            ->get();

        $onTime = $due->filter(
            fn (Task $task) => $task->status === TaskStatus::Done
                && $task->completed_at !== null
                && $task->completed_at->lessThanOrEqualTo($task->due_at),
        );

        return [
            'rate' => $due->isEmpty() ? null : (int) round($onTime->count() / $due->count() * 100),
            'closed' => $onTime->count(),
            'due' => $due->count(),
        ];
    }
}
