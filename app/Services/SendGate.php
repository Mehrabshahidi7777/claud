<?php

namespace App\Services;

use App\Models\SmsOutbound;
use App\Models\TaskFollowUp;
use Carbon\CarbonImmutable;

/**
 * The seven conditions every outbound message clears before it is sent.
 *
 * A verdict is one of three things, and the difference matters: `send` goes
 * now, `defer` waits for a better moment and stays pending, `skip` never goes
 * and records why. Collapsing defer into skip is what makes a system go quiet
 * over a weekend and never recover.
 */
class SendGate
{
    public const SEND = 'send';

    public const DEFER = 'defer';

    public const SKIP = 'skip';

    /**
     * @return array{verdict: string, reason: ?string, retry_at: ?CarbonImmutable}
     */
    public function evaluate(TaskFollowUp $followUp): array
    {
        $task = $followUp->task;
        $recipient = $followUp->recipient;
        $workspace = $task->workspace;
        $now = CarbonImmutable::now();

        // 1. The task is still open. A reply that arrived while this rung sat
        //    in the queue has already settled it.
        if ($task->status->isClosed()) {
            return $this->skip('task_closed');
        }

        if (! $task->status->isChaseable()) {
            return $this->skip('task_deferred');
        }

        // 2. The recipient still exists and still accepts messages.
        if ($recipient === null) {
            return $this->skip('no_recipient');
        }

        if ($recipient->hasOptedOutOfSms()) {
            return $this->skip('recipient_opted_out');
        }

        if ($this->isAway($followUp)) {
            return $this->skip('recipient_away');
        }

        // 3. Sending is switched on, globally and for this workspace.
        if (! config('sms.enabled', true)) {
            return $this->skip('sending_disabled_globally');
        }

        if (! $workspace->sms_enabled) {
            return $this->skip('sending_disabled_for_workspace');
        }

        // 4. The priority warrants an SMS at all.
        if (! $task->priority->warrantsSms()) {
            return $this->skip('priority_below_sms_threshold');
        }

        // 5. Not inside quiet hours or a holiday — and if it is, wait rather
        //    than drop. A critical task may break through, but only when the
        //    manager consented at creation time.
        $hours = new WorkingHours($workspace);

        if (! $hours->isSendable($now) && ! $this->mayBreakQuietHours($followUp)) {
            return $this->defer('quiet_hours', $hours->nextSendableMoment($now));
        }

        // 6. The recipient's own caps. Being chased four times in a day
        //    teaches people to ignore the channel entirely.
        if ($verdict = $this->checkRecipientCaps($followUp, $now)) {
            return $verdict;
        }

        // 7. The workspace has credit left.
        if (! $workspace->hasSmsCredit()) {
            return $this->skip('workspace_out_of_sms_credit');
        }

        return ['verdict' => self::SEND, 'reason' => null, 'retry_at' => null];
    }

    /**
     * @return array{verdict: string, reason: ?string, retry_at: ?CarbonImmutable}|null
     */
    private function checkRecipientCaps(TaskFollowUp $followUp, CarbonImmutable $now): ?array
    {
        $recipientId = $followUp->recipient_id;

        $today = SmsOutbound::query()
            ->where('user_id', $recipientId)
            ->where('created_at', '>=', $now->startOfDay())
            ->count();

        if ($today >= (int) config('followup.caps.per_user_per_day', 3)) {
            return $this->defer('daily_cap_reached', $now->addDay()->startOfDay());
        }

        $thisWeek = SmsOutbound::query()
            ->where('user_id', $recipientId)
            ->where('created_at', '>=', $now->subDays(7))
            ->count();

        if ($thisWeek >= (int) config('followup.caps.per_user_per_week', 10)) {
            return $this->defer('weekly_cap_reached', $now->addDay());
        }

        $spacing = (int) config('followup.caps.min_minutes_between_messages', 90);

        $lastSentAt = SmsOutbound::query()
            ->where('user_id', $recipientId)
            ->latest('created_at')
            ->value('created_at');

        if ($lastSentAt !== null) {
            $nextAllowed = CarbonImmutable::parse($lastSentAt)->addMinutes($spacing);

            if ($now < $nextAllowed) {
                return $this->defer('too_soon_after_last_message', $nextAllowed);
            }
        }

        return null;
    }

    /**
     * Priority alone is not consent. The manager has to have ticked the box
     * when the task was created.
     */
    private function mayBreakQuietHours(TaskFollowUp $followUp): bool
    {
        return $followUp->task->priority->isCritical()
            && $followUp->task->may_break_quiet_hours;
    }

    private function isAway(TaskFollowUp $followUp): bool
    {
        $membership = $followUp->recipient
            ?->workspaces()
            ->where('workspaces.id', $followUp->task->workspace_id)
            ->first()
            ?->pivot;

        if ($membership === null) {
            return false;
        }

        if ($membership->deactivated_at !== null) {
            return true;
        }

        return $membership->away_until !== null
            && CarbonImmutable::parse($membership->away_until)->isFuture();
    }

    /**
     * @return array{verdict: string, reason: string, retry_at: null}
     */
    private function skip(string $reason): array
    {
        return ['verdict' => self::SKIP, 'reason' => $reason, 'retry_at' => null];
    }

    /**
     * @return array{verdict: string, reason: string, retry_at: CarbonImmutable}
     */
    private function defer(string $reason, CarbonImmutable $retryAt): array
    {
        return ['verdict' => self::DEFER, 'reason' => $reason, 'retry_at' => $retryAt];
    }
}
