<?php

namespace App\Services;

use App\Contracts\SmsDriver;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\ApprovalRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalAwaiting;
use App\Notifications\ApprovalDecided;
use App\Sms\PatternMessage;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave and spending requests, and what happens once someone says yes.
 *
 * The part that is not paperwork: an approved leave writes `away_until` onto
 * the membership, and the follow-up engine already refuses to chase someone
 * who is away. So the company stops sending "چرا انجام نشد؟" to a person it
 * signed the leave form for itself — which is the single complaint that makes
 * people abandon a follow-up system.
 */
class ApprovalService
{
    public function __construct(private readonly SmsDriver $sms) {}

    /**
     * @param  array{type: ApprovalType, title: string, reason?: ?string, starts_on?: ?string, ends_on?: ?string, amount?: ?int}  $attributes
     */
    public function submit(Workspace $workspace, User $requester, array $attributes): ApprovalRequest
    {
        $request = ApprovalRequest::create([
            'workspace_id' => $workspace->id,
            'requester_id' => $requester->id,
            'approver_id' => $this->resolveApprover($workspace, $requester)?->id,
            'status' => ApprovalStatus::Pending,
            'type' => $attributes['type'],
            'title' => $attributes['title'],
            'reason' => $attributes['reason'] ?? null,
            'starts_on' => $attributes['starts_on'] ?? null,
            'ends_on' => $attributes['ends_on'] ?? null,
            'amount' => $attributes['amount'] ?? null,
        ]);

        Activity::record($request, 'approval.submitted', $workspace->id, $requester->id, [
            'type' => $request->type->value,
        ]);

        $this->notifyDeciders($workspace, $request);

        return $request;
    }

    public function approve(ApprovalRequest $request, User $decider, ?string $note = null): ApprovalRequest
    {
        return $this->decide($request, $decider, ApprovalStatus::Approved, $note);
    }

    public function reject(ApprovalRequest $request, User $decider, ?string $note = null): ApprovalRequest
    {
        return $this->decide($request, $decider, ApprovalStatus::Rejected, $note);
    }

    public function cancel(ApprovalRequest $request, User $requester): ApprovalRequest
    {
        return $this->decide($request, $requester, ApprovalStatus::Cancelled, null);
    }

    /**
     * The tasks that fall inside a requested leave, assigned to the person
     * asking for it.
     *
     * This is what the approver sees before deciding. "مرخصی سه روزه" means
     * nothing on its own; "مرخصی سه روزه، و این دو تسک سررسیدشان در همان بازه
     * است" is a decision someone can actually make.
     *
     * @return Collection<int, Task>
     */
    public function conflictingTasks(ApprovalRequest $request): Collection
    {
        if ($request->type !== ApprovalType::Leave || $request->starts_on === null) {
            return new Collection;
        }

        $timezone = $request->workspace->timezone();

        return Task::query()
            ->chaseable()
            ->forWorkspace($request->workspace_id)
            ->where('assignee_id', $request->requester_id)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [
                CarbonImmutable::parse($request->starts_on->toDateString(), $timezone)->startOfDay()->utc(),
                CarbonImmutable::parse($request->ends_on->toDateString(), $timezone)->endOfDay()->utc(),
            ])
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Owners and admins, plus the named approver. A request nobody can see is
     * a request that never gets answered.
     *
     * @return Collection<int, User>
     */
    public function decidersFor(Workspace $workspace, ApprovalRequest $request): Collection
    {
        if ($request->approver_id !== null) {
            return $workspace->members()->where('users.id', $request->approver_id)->get();
        }

        return $workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->where('users.id', '!=', $request->requester_id)
            ->get();
    }

    /**
     * Whether this person may decide this request. The named approver, or any
     * owner or admin when nobody is named — but never the person who asked,
     * whatever their role. An owner approving their own leave is a record
     * nobody believes.
     */
    public function canDecide(ApprovalRequest $request, User $user, WorkspaceRole $role): bool
    {
        if ($request->status->isDecided() || $request->requester_id === $user->id) {
            return false;
        }

        if ($request->approver_id !== null) {
            return $request->approver_id === $user->id || $role === WorkspaceRole::Owner;
        }

        return $role->canManageMembers();
    }

    private function decide(
        ApprovalRequest $request,
        User $actor,
        ApprovalStatus $status,
        ?string $note,
    ): ApprovalRequest {
        DB::transaction(function () use ($request, $actor, $status, $note) {
            $request->update([
                'status' => $status,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            if ($status === ApprovalStatus::Approved && $request->type === ApprovalType::Leave) {
                $this->applyLeave($request);
            }
        });

        Activity::record($request, 'approval.'.$status->value, $request->workspace_id, $actor->id);

        if ($status !== ApprovalStatus::Cancelled) {
            $this->notifyRequester($request->fresh(['requester', 'workspace']));
        }

        return $request->refresh();
    }

    /**
     * Write the leave onto the membership, which is where the send gate reads
     * it. Never shortened: someone with leave already running to the end of the
     * month does not lose it by being granted two days next week.
     */
    private function applyLeave(ApprovalRequest $request): void
    {
        $workspace = $request->workspace;
        $timezone = $workspace->timezone();

        $until = CarbonImmutable::parse($request->ends_on->toDateString(), $timezone)->endOfDay()->utc();

        $membership = $workspace->members()
            ->where('users.id', $request->requester_id)
            ->first()
            ?->pivot;

        if ($membership === null) {
            return;
        }

        if ($membership->away_until !== null) {
            $existing = CarbonImmutable::parse($membership->away_until);
            $until = $existing->greaterThan($until) ? $existing : $until;
        }

        $workspace->members()->updateExistingPivot($request->requester_id, [
            'away_until' => $until,
        ]);
    }

    /**
     * The requester's manager on the pivot. Null when nobody is named, and
     * that is a legitimate state — small workspaces never fill it in — so the
     * request falls to the owners and admins instead of to nobody.
     */
    private function resolveApprover(Workspace $workspace, User $requester): ?User
    {
        $managerId = $workspace->members()
            ->where('users.id', $requester->id)
            ->first()
            ?->pivot
            ?->manager_id;

        if ($managerId === null || (int) $managerId === $requester->id) {
            return null;
        }

        return $workspace->members()->where('users.id', $managerId)->first();
    }

    private function notifyDeciders(Workspace $workspace, ApprovalRequest $request): void
    {
        $deciders = $this->decidersFor($workspace, $request);

        foreach ($deciders as $decider) {
            $decider->notify(new ApprovalAwaiting($request));
        }

        if (! $workspace->sms_enabled || ! $workspace->hasSmsCredit($deciders->count())) {
            return;
        }

        foreach ($deciders as $decider) {
            if ($decider->hasOptedOutOfSms()) {
                continue;
            }

            $message = PatternMessage::make($decider->phone, 'approval_request', [
                'type' => $request->type->label(),
                'name' => $request->requester->firstName(),

                // Twenty rather than the usual twenty-five: this template
                // carries two other variable pieces, and a split message costs
                // double for no extra information.
                'title' => PersianText::truncate($request->title, 20),
            ]);

            if ($this->sms->send($message)->successful) {
                $workspace->consumeSmsCredit($message->segments());
            }
        }
    }

    private function notifyRequester(ApprovalRequest $request): void
    {
        $requester = $request->requester;

        if ($requester === null) {
            return;
        }

        $requester->notify(new ApprovalDecided($request));

        $workspace = $request->workspace;

        if (! $workspace->sms_enabled || ! $workspace->hasSmsCredit() || $requester->hasOptedOutOfSms()) {
            return;
        }

        $message = PatternMessage::make($requester->phone, 'approval_decision', [
            'result' => $request->status === ApprovalStatus::Approved ? 'تأیید' : 'رد',
            'title' => PersianText::truncate($request->title),
        ]);

        if ($this->sms->send($message)->successful) {
            $workspace->consumeSmsCredit($message->segments());
        }
    }
}
