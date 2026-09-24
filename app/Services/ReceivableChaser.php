<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\Receivable;
use App\Models\Task;
use App\Models\Workspace;
use App\Support\PersianText;

/**
 * Turns money nobody is collecting into work somebody is chased about.
 *
 * This is the one thing the company's accounting package cannot do. It knows
 * perfectly well that an invoice is ninety days late — it will print that in
 * a report every month, and every month nobody reads the report. Here the
 * overdue receivable becomes an ordinary task, which means it enters the
 * ladder: a notification, then an SMS to whoever owns it, then an escalation
 * to their manager.
 *
 * The customer is never texted. A dedicated line that messages strangers
 * about their debts is a regulatory complaint waiting to happen, and the
 * person who should be uncomfortable is the one inside the company who has
 * not picked up the phone.
 */
class ReceivableChaser
{
    public function __construct(private readonly FollowUpScheduler $scheduler) {}

    /**
     * @return int how many chase tasks were raised
     */
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
        $grace = (int) config('finance.chase_after_days', 3);

        $due = Receivable::forWorkspace($workspace->id)
            ->outstanding()
            ->with('owner')
            ->whereNull('task_id')
            ->whereDate('due_on', '<', now()->subDays($grace)->toDateString())
            ->get();

        $raised = 0;

        foreach ($due as $receivable) {
            if ($this->raiseChase($workspace, $receivable)) {
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * A chase task is raised once per receivable, which `task_id` guarantees.
     * Raising a second one every night would bury the first in noise and burn
     * the recipient's daily SMS cap on a single unpaid invoice.
     */
    private function raiseChase(Workspace $workspace, Receivable $receivable): bool
    {
        $owner = $receivable->owner_id !== null
            ? $receivable->owner
            : $this->fallbackOwner($workspace);

        if ($owner === null) {
            return false;
        }

        $task = Task::create([
            'workspace_id' => $workspace->id,
            'title' => sprintf(
                'پیگیری وصول: %s — %s',
                PersianText::truncate($receivable->customer_name, 30),
                PersianText::truncate($receivable->title, 30),
            ),
            'description' => sprintf(
                "مبلغ باقی‌مانده: %s ریال\nسررسید: %s\n%s روز گذشته است.",
                number_format($receivable->outstanding()),
                $receivable->due_on->toDateString(),
                $receivable->daysOverdue(),
            ),
            'assignee_id' => $owner->id,
            'creator_id' => $owner->id,

            // Tomorrow's end of day, not right now: a chase task raised at
            // 2am with a deadline of 2am is already overdue when it is born.
            'due_at' => now()->addDay()->setTime(17, 0),
            'priority' => TaskPriority::High,
            'status' => TaskStatus::Open,
        ]);

        $receivable->update(['task_id' => $task->id]);

        $this->scheduler->scheduleFor($task);

        Activity::record($receivable, 'receivable.chase_raised', $workspace->id, null, [
            'task_id' => $task->id,
            'days_overdue' => $receivable->daysOverdue(),
        ]);

        return true;
    }

    /**
     * Nobody named means it lands with the workspace owner. An unpaid invoice
     * with no owner is exactly the one that goes uncollected, so it must not
     * fall through to nobody.
     */
    private function fallbackOwner(Workspace $workspace)
    {
        return $workspace->members()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->first();
    }
}
