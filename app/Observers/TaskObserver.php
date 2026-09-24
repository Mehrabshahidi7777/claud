<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\RecurrenceSweeper;

/**
 * Advancing a recurring cycle happens here rather than in each controller.
 *
 * A task can be closed from the form, from an inbound SMS reply, or from
 * whatever path gets added next. Hanging the advance off the model means the
 * cycle moves however it was closed, instead of moving in the two places
 * somebody remembered and silently stalling in the third.
 */
class TaskObserver
{
    public function updated(Task $task): void
    {
        if (! $task->wasChanged('status')) {
            return;
        }

        if ($task->recurring_task_id === null || $task->status !== TaskStatus::Done) {
            return;
        }

        app(RecurrenceSweeper::class)->completeCycle($task);
    }
}
