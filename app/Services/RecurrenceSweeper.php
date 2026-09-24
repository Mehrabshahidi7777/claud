<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Raises the next occurrence of recurring work, and advances the cycle once
 * an occurrence is finished.
 *
 * For a service company this is the module that makes money rather than
 * reporting it. A six-monthly service is only ever invoiced when somebody
 * remembers to ring the customer, so most of them are never invoiced at all —
 * the contract exists, the revenue does not. Here the reminder is not a
 * calendar entry nobody opens: it becomes a task, which means it enters the
 * ladder and somebody is actually chased about it.
 */
class RecurrenceSweeper
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
        $raised = 0;

        $candidates = RecurringTask::forWorkspace($workspace->id)
            ->active()
            ->with('assignee')
            ->get()
            ->filter->isDueToRaise();

        foreach ($candidates as $recurrence) {
            if ($this->raise($recurrence)) {
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * Advance a recurrence because one of its tasks was completed.
     *
     * Called from the Task observer rather than from each controller, so a
     * cycle advances no matter how the task was closed — by the form, by an
     * SMS reply, or by anything added later.
     */
    public function completeCycle(Task $task): void
    {
        $recurrence = $task->recurringTask;

        if ($recurrence === null) {
            return;
        }

        $completedOn = $task->completed_at !== null
            ? CarbonImmutable::parse($task->completed_at)
            : CarbonImmutable::now();

        $from = $recurrence->nextCycleFrom($completedOn);

        $next = $recurrence->interval_unit->advance($from, $recurrence->interval_count);

        // A cycle so short that the next one is already due would raise a
        // fresh task on the very next sweep and keep doing it. Push forward
        // until the next occurrence is genuinely in the future.
        while ($next->startOfDay()->lessThanOrEqualTo(CarbonImmutable::now()->startOfDay())) {
            $next = $recurrence->interval_unit->advance($next, $recurrence->interval_count);
        }

        $recurrence->update([
            'next_due_on' => $next->toDateString(),
            'last_done_on' => $completedOn->toDateString(),
            'occurrences' => $recurrence->occurrences + 1,
        ]);

        Activity::record($recurrence, 'recurrence.cycle_completed', $recurrence->workspace_id, null, [
            'next_due_on' => $next->toDateString(),
            'occurrences' => $recurrence->occurrences + 1,
        ]);
    }

    /**
     * One open task per recurrence at a time. Without this the sweep raises a
     * fresh copy every night from the lead date until the due date — seven
     * identical tasks for one service, and a recipient who stops reading any
     * of them.
     */
    private function raise(RecurringTask $recurrence): bool
    {
        $alreadyOpen = $recurrence->tasks()
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->exists();

        if ($alreadyOpen) {
            return false;
        }

        $assignee = $recurrence->assignee_id ?? $recurrence->created_by;

        $task = Task::create([
            'workspace_id' => $recurrence->workspace_id,
            'recurring_task_id' => $recurrence->id,
            'title' => $recurrence->isServiceContract()
                ? $recurrence->title.' — '.$recurrence->customer_name
                : $recurrence->title,
            'description' => $this->describe($recurrence),
            'assignee_id' => $assignee,
            'creator_id' => $recurrence->created_by,
            'due_at' => CarbonImmutable::parse($recurrence->next_due_on)->setTime(17, 0),
            'priority' => $recurrence->priority,
            'status' => TaskStatus::Open,
        ]);

        $this->scheduler->scheduleFor($task);

        Activity::record($recurrence, 'recurrence.occurrence_raised', $recurrence->workspace_id, null, [
            'task_id' => $task->id,
        ]);

        return true;
    }

    private function describe(RecurringTask $recurrence): string
    {
        $lines = [];

        if ($recurrence->description !== null) {
            $lines[] = $recurrence->description;
        }

        if ($recurrence->isServiceContract()) {
            $lines[] = 'مشتری: '.$recurrence->customer_name
                .($recurrence->customer_phone !== null ? ' — '.$recurrence->customer_phone : '');
        }

        $lines[] = 'دوره: '.$recurrence->intervalLabel();

        if ($recurrence->last_done_on !== null) {
            $lines[] = 'آخرین بار: '.$recurrence->last_done_on->toDateString();
        }

        if ($recurrence->estimated_value !== null) {
            $lines[] = 'ارزش تقریبی: '.number_format($recurrence->estimated_value).' ریال';
        }

        return implode("\n", $lines);
    }
}
