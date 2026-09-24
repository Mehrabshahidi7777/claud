<?php

namespace App\Services;

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskFollowUp;
use Carbon\CarbonImmutable;

/**
 * Builds a task's ladder. Called when a task gets a deadline and again every
 * time that deadline moves — rungs that have already fired are history and are
 * left alone, unsent ones are rebuilt against the new date.
 */
class FollowUpScheduler
{
    public function scheduleFor(Task $task): void
    {
        if ($task->due_at === null || $task->status->isClosed()) {
            $this->clearPending($task);

            return;
        }

        $recipient = $task->responsible();

        if ($recipient === null) {
            $this->clearPending($task);

            return;
        }

        $due = CarbonImmutable::parse($task->due_at);
        $managerId = $this->managerIdFor($task);

        foreach ($this->rungs($task, $due) as $step => $moment) {
            $step = FollowUpStep::from($step);

            // The escalation is addressed to the manager, resolved now rather
            // than at send time so a later reporting-line change cannot
            // silently redirect a message that was already scheduled.
            $recipientId = $step->goesToManager()
                ? ($managerId ?? $recipient->id)
                : $recipient->id;

            TaskFollowUp::updateOrCreate(
                ['task_id' => $task->id, 'step' => $step->value],
                [
                    'channel' => $step->channel(),
                    'recipient_id' => $recipientId,
                    'scheduled_at' => $moment,
                    'status' => FollowUpStatus::Pending,
                    'skip_reason' => null,
                ],
            );
        }
    }

    /**
     * When a rung fires, the next one is scheduled from that moment rather
     * than from the deadline — an escalation is four hours after the chase
     * actually went out, not four hours after a chase that quiet hours delayed
     * until morning.
     */
    public function scheduleEscalation(TaskFollowUp $chase): void
    {
        $task = $chase->task;

        if (! $task->status->isChaseable()) {
            return;
        }

        // A household has no reporting line. Texting someone's spouse because
        // the bins are still out has misunderstood the home it was invited
        // into, and that is the message that gets the product uninstalled.
        if (! $task->workspace->type->hasEscalation()) {
            return;
        }

        $delay = $task->priority->isCritical()
            ? (float) config('followup.critical.escalate_hours_after_chase', 1.5)
            : (float) $task->workspace->setting('ladder.escalate_hours_after_chase', 4);

        TaskFollowUp::updateOrCreate(
            ['task_id' => $task->id, 'step' => FollowUpStep::Escalate->value],
            [
                'channel' => FollowUpStep::Escalate->channel(),
                'recipient_id' => $this->managerIdFor($task) ?? $task->responsible()?->id,
                'scheduled_at' => CarbonImmutable::now()->addMinutes((int) round($delay * 60)),
                'status' => FollowUpStatus::Pending,
                'skip_reason' => null,
            ],
        );
    }

    /**
     * The rungs and when each falls due.
     *
     * A task created after its own deadline skips the two reminder rungs
     * outright — reminding someone about something already late is noise.
     *
     * @return array<int, CarbonImmutable>
     */
    private function rungs(Task $task, CarbonImmutable $due): array
    {
        $workspace = $task->workspace;
        $critical = $task->priority->isCritical();

        $chaseDelay = $critical
            ? (float) config('followup.critical.chase_hours_after', 0)
            : (float) $workspace->setting('ladder.chase_hours_after', 2);

        $rungs = [
            FollowUpStep::Chase->value => $due->addMinutes((int) round($chaseDelay * 60)),
        ];

        $nudgeAt = $due->subHours((int) $workspace->setting('ladder.nudge_hours_before', 24));

        if ($nudgeAt->isFuture()) {
            $rungs[FollowUpStep::Nudge->value] = $nudgeAt;
        }

        $morningHour = (float) $workspace->setting('ladder.due_morning_hour', 8.5);
        $dueMorning = $due
            ->setTimezone($workspace->timezone())
            ->setTime((int) $morningHour, (int) round(fmod($morningHour, 1) * 60))
            ->utc();

        if ($dueMorning->isFuture() && $dueMorning < $due) {
            $rungs[FollowUpStep::DueMorning->value] = $dueMorning;
        }

        ksort($rungs);

        return $rungs;
    }

    /**
     * Where an escalation lands, in order of who actually knows about the work.
     *
     * The named manager first. Then the head of the person's own department —
     * in a company of forty, a chase about a purchase order means something
     * to the head of commercial and nothing to the managing director. The
     * owner is the last resort, which is the right answer only for a small
     * company with no real hierarchy.
     */
    private function managerIdFor(Task $task): ?int
    {
        $membership = $task->responsible()
            ?->workspaces()
            ->where('workspaces.id', $task->workspace_id)
            ->first()
            ?->pivot;

        if ($membership?->manager_id !== null) {
            return $membership->manager_id;
        }

        $lead = $this->departmentLeadFor($task, $membership?->department_id);

        return $lead
            ?? $task->workspace->members()->wherePivot('role', 'owner')->first()?->id;
    }

    /**
     * The head of the relevant department — the task's own where it has one,
     * otherwise the one the assignee sits in. A lead is never escalated to
     * about their own work, which would be a message to nobody.
     */
    private function departmentLeadFor(Task $task, ?int $memberDepartmentId): ?int
    {
        $departmentId = $task->department_id ?? $memberDepartmentId;

        if ($departmentId === null) {
            return null;
        }

        $leadId = Department::where('id', $departmentId)
            ->where('workspace_id', $task->workspace_id)
            ->value('lead_id');

        return $leadId !== null && $leadId !== $task->responsible()?->id ? $leadId : null;
    }

    private function clearPending(Task $task): void
    {
        $task->followUps()
            ->where('status', FollowUpStatus::Pending->value)
            ->delete();
    }
}
