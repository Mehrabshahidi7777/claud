<?php

namespace App\Services;

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Enums\TaskStatus;
use App\Models\SmsInbound;
use App\Models\SmsOutbound;
use App\Models\Task;
use App\Models\TaskFollowUp;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * The numbers a manager buys the product for, and the numbers that tell us
 * whether the engine is working.
 *
 * On-time completion is the one that proves ROI and gets the subscription
 * renewed; the other two are how we find out the engine has turned into a
 * spam machine before the customer does.
 */
class ReportBuilder
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @return array<string, mixed>
     */
    public function weekly(?CarbonImmutable $from = null): array
    {
        $from ??= CarbonImmutable::now()->subDays(7);

        return [
            'period_from' => $from,
            'period_to' => CarbonImmutable::now(),
            'on_time_rate' => $this->onTimeCompletionRate($from),
            'chase_response_rate' => $this->chaseResponseRate($from),
            'escalation_ratio' => $this->escalationRatio($from),
            'overdue' => $this->overdueTasks(),
            'escalated' => $this->escalatedTasks($from),
            'by_member' => $this->perMember($from),
            'sms_used' => $this->workspace->sms_used,
            'sms_remaining' => $this->workspace->remainingSmsCredit(),
            'sms_sent_this_period' => $this->smsSent($from),
        ];
    }

    /**
     * The headline number. Closed on or before the deadline, over everything
     * that was closed in the period — a task closed late still counts against
     * us, which is the point.
     */
    public function onTimeCompletionRate(CarbonImmutable $from): ?float
    {
        $closed = Task::forWorkspace($this->workspace->id)
            ->where('status', TaskStatus::Done->value)
            ->whereNotNull('completed_at')
            ->whereNotNull('due_at')
            ->where('completed_at', '>=', $from)
            ->get(['due_at', 'completed_at']);

        if ($closed->isEmpty()) {
            return null;
        }

        $onTime = $closed->filter(fn ($task) => $task->completed_at <= $task->due_at)->count();

        return round($onTime / $closed->count() * 100, 1);
    }

    /**
     * Below 60% means the wording or the timing is wrong, not that people are
     * ignoring their work.
     */
    public function chaseResponseRate(CarbonImmutable $from): ?float
    {
        $chases = TaskFollowUp::query()
            ->whereHas('task', fn ($q) => $q->where('workspace_id', $this->workspace->id))
            ->where('step', FollowUpStep::Chase->value)
            ->where('status', FollowUpStatus::Sent->value)
            ->where('sent_at', '>=', $from)
            ->count();

        if ($chases === 0) {
            return null;
        }

        $answered = SmsInbound::query()
            ->whereHas('matchedTask', fn ($q) => $q->where('workspace_id', $this->workspace->id))
            ->where('received_at', '>=', $from)
            ->where('applied', true)
            ->count();

        return round(min($answered / $chases, 1) * 100, 1);
    }

    /**
     * Above 25% and the manager is being spammed — they will switch the engine
     * off long before they cancel, and then the product does nothing at all.
     */
    public function escalationRatio(CarbonImmutable $from): ?float
    {
        $chases = TaskFollowUp::query()
            ->whereHas('task', fn ($q) => $q->where('workspace_id', $this->workspace->id))
            ->where('step', FollowUpStep::Chase->value)
            ->where('status', FollowUpStatus::Sent->value)
            ->where('sent_at', '>=', $from)
            ->count();

        if ($chases === 0) {
            return null;
        }

        $escalations = TaskFollowUp::query()
            ->whereHas('task', fn ($q) => $q->where('workspace_id', $this->workspace->id))
            ->where('step', FollowUpStep::Escalate->value)
            ->where('status', FollowUpStatus::Sent->value)
            ->where('sent_at', '>=', $from)
            ->count();

        return round($escalations / $chases * 100, 1);
    }

    public function overdueTasks()
    {
        return Task::forWorkspace($this->workspace->id)
            ->chaseable()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->with('assignee')
            ->orderBy('due_at')
            ->get();
    }

    public function escalatedTasks(CarbonImmutable $from)
    {
        return Task::forWorkspace($this->workspace->id)
            ->where('status', TaskStatus::Escalated->value)
            ->where('updated_at', '>=', $from)
            ->with('assignee')
            ->get();
    }

    /**
     * Per-person figures. A member who is never late and a member who is
     * always late look identical in a total, and the difference is the whole
     * conversation a manager wants to have.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perMember(CarbonImmutable $from): array
    {
        return $this->workspace->members()->orderBy('name')->get()->map(function ($member) use ($from) {
            $tasks = Task::forWorkspace($this->workspace->id)
                ->where('assignee_id', $member->id)
                ->where('created_at', '>=', $from)
                ->get(['status', 'due_at', 'completed_at']);

            $closed = $tasks->where('status', TaskStatus::Done)->whereNotNull('completed_at');
            $onTime = $closed->filter(fn ($t) => $t->due_at !== null && $t->completed_at <= $t->due_at)->count();

            return [
                'name' => $member->name,
                'phone' => $member->localPhone(),
                'total' => $tasks->count(),
                'closed' => $closed->count(),
                'on_time' => $onTime,
                'overdue' => $tasks->filter(
                    fn ($t) => ! $t->status->isClosed() && $t->due_at !== null && $t->due_at->isPast()
                )->count(),
                'opted_out' => $member->hasOptedOutOfSms(),
            ];
        })->all();
    }

    private function smsSent(CarbonImmutable $from): int
    {
        return (int) SmsOutbound::where('workspace_id', $this->workspace->id)
            ->where('created_at', '>=', $from)
            ->where('status', 'sent')
            ->sum('segments');
    }

    /**
     * Everything the weekly report needs, as one frozen snapshot.
     *
     * A percentage on its own says nothing a manager can act on. What makes
     * the Saturday email worth opening is the direction it moved and the
     * names behind it, so both are computed here rather than left for the
     * reader to work out.
     *
     * @return array<string, mixed>
     */
    public function snapshot(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $previousFrom = $from->sub($from->diffAsCarbonInterval($to));

        $current = [
            'on_time_rate' => $this->onTimeCompletionRate($from),
            'chase_response_rate' => $this->chaseResponseRate($from),
            'escalation_ratio' => $this->escalationRatio($from),
        ];

        return [
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'headline' => $current,
            'change' => [
                'on_time_rate' => $this->delta($current['on_time_rate'], $this->onTimeCompletionRate($previousFrom)),
                'chase_response_rate' => $this->delta($current['chase_response_rate'], $this->chaseResponseRate($previousFrom)),
                'escalation_ratio' => $this->delta($current['escalation_ratio'], $this->escalationRatio($previousFrom)),
            ],
            'counts' => [
                'created' => Task::forWorkspace($this->workspace->id)->where('created_at', '>=', $from)->count(),
                'closed' => Task::forWorkspace($this->workspace->id)
                    ->where('status', TaskStatus::Done->value)
                    ->where('completed_at', '>=', $from)
                    ->count(),
                'overdue_now' => $this->overdueTasks()->count(),
                'escalated' => $this->escalatedTasks($from)->count(),
            ],
            'overdue' => $this->overdueTasks()->take(10)->map(fn ($task) => [
                'title' => $task->title,
                'assignee' => $task->assignee?->name,
                'hours_overdue' => $task->hoursOverdue(),
            ])->values()->all(),
            'at_risk' => $this->tasksAtRisk(),
            'by_member' => $this->perMember($from),
            'sms' => [
                'sent' => $this->smsSent($from),
                'used' => $this->workspace->sms_used,
                'remaining' => $this->workspace->remainingSmsCredit(),
            ],
        ];
    }

    /**
     * Work that is not late yet but will be by Monday unless somebody moves.
     * A report that only lists what already failed arrives too late to be
     * worth anything.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tasksAtRisk(int $withinHours = 72): array
    {
        return Task::forWorkspace($this->workspace->id)
            ->chaseable()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addHours($withinHours)])
            ->with('assignee')
            ->orderBy('due_at')
            ->get()
            ->map(fn ($task) => [
                'title' => $task->title,
                'assignee' => $task->assignee?->name,
                'due_at' => $task->due_at->toDateString(),
                // Somebody already carrying overdue work is the likeliest
                // person to miss the next deadline too.
                'assignee_already_overdue' => $task->assignee !== null && Task::forWorkspace($this->workspace->id)
                    ->chaseable()
                    ->where('assignee_id', $task->assignee_id)
                    ->where('due_at', '<', now())
                    ->exists(),
            ])
            ->values()
            ->all();
    }

    /**
     * The change in a percentage between two periods, or null when either
     * side has no data. Reporting a move from nothing as "+71٪" is a lie the
     * first week of every new customer would tell.
     */
    private function delta(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return round($current - $previous, 1);
    }
}
