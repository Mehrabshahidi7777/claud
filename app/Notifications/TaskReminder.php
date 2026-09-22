<?php

namespace App\Notifications;

use App\Enums\FollowUpStep;
use App\Models\Task;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The first two rungs of the ladder.
 *
 * They cost nothing and reach the assignee before anything has gone wrong,
 * which is the whole reason the SMS rungs stay rare enough to be taken
 * seriously. Stored in the database only — an email for every upcoming task
 * would train people to filter the address the escalations come from.
 */
class TaskReminder extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Task $task,
        private readonly FollowUpStep $step,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'workspace_id' => $this->task->workspace_id,
            'step' => $this->step->value,
            'title' => $this->task->title,
            'due_at' => $this->task->due_at?->toIso8601String(),
            'message' => $this->message(),
        ];
    }

    private function message(): string
    {
        $due = $this->task->due_at === null
            ? null
            : JalaliDate::format(CarbonImmutable::parse($this->task->due_at));

        return match ($this->step) {
            FollowUpStep::Nudge => $due === null
                ? sprintf('«%s» فردا سررسید می‌شود.', $this->task->title)
                : sprintf('«%s» فردا (%s) سررسید می‌شود.', $this->task->title, $due),

            FollowUpStep::DueMorning => sprintf('«%s» امروز سررسید است.', $this->task->title),

            default => $this->task->title,
        };
    }
}
