<?php

namespace App\Models;

use App\Enums\FollowUpChannel;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskFollowUp extends Model
{
    use HasFactory;

    /**
     * Engine bookkeeping only — rows here are never built from request input,
     * so guarding the columns would buy nothing and silently drop the ones the
     * runner writes (skip_reason above all, which is the first thing anyone
     * looks at when a customer says the system went quiet).
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'step' => FollowUpStep::class,
            'channel' => FollowUpChannel::class,
            'status' => FollowUpStatus::class,
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * When this rung goes out, in words.
     *
     * A pending rung whose moment has already passed is waiting on the next
     * sweep, not something that happened in the past — and "پیامک پیگیری، یک
     * ماه پیش" on a screen headed "قدم بعدی سامانه" reads as a bug rather
     * than as a queue.
     */
    public function whenDue(): string
    {
        if ($this->status !== FollowUpStatus::Pending) {
            return $this->sent_at?->diffForHumans() ?? '—';
        }

        return $this->scheduled_at->isFuture()
            ? $this->scheduled_at->diffForHumans()
            : 'در صف اجرا';
    }

    /**
     * Why this rung never went out, in words a manager can act on.
     *
     * The skip reason is the single most useful column in the system when
     * someone asks "پس چرا پیامک نرفت؟", and a raw `recipient_opted_out` on
     * screen answers nobody. Anything unmapped falls through to the raw value
     * rather than a vague sentence, so a new reason is visibly unfinished
     * instead of silently meaningless.
     */
    public function skipExplanation(): ?string
    {
        return match ($this->skip_reason) {
            null => null,
            'task_closed' => 'تسک قبلش بسته شده بود',
            'task_deferred' => 'تسک به تعویق افتاده بود',
            'no_recipient' => 'گیرنده‌ای نداشت',
            'recipient_opted_out' => 'گیرنده دریافت پیامک را قطع کرده',
            'recipient_away' => 'گیرنده مرخصی تأییدشده داشت',
            'sending_disabled_globally' => 'ارسال پیامک سراسری خاموش بود',
            'sending_disabled_for_workspace' => 'ارسال پیامک این فضای کاری خاموش بود',
            'priority_below_sms_threshold' => 'اولویت تسک پایین‌تر از حد پیامک بود',
            'workspace_out_of_sms_credit' => 'اعتبار پیامک تمام شده بود',
            'subscription_expired' => 'اشتراک منقضی شده بود',
            default => $this->skip_reason,
        };
    }

    /**
     * Rungs the sweep should act on. Anything already sent, skipped or failed
     * is history — the unique index on (task_id, step) makes sure a retry
     * cannot resurrect it.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', FollowUpStatus::Pending->value)
            ->where('scheduled_at', '<=', now());
    }

    public function markSent(): void
    {
        $this->update([
            'status' => FollowUpStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * A skip is recorded rather than deleted. "Why did it go quiet?" is the
     * first question a customer asks, and this column is the answer.
     */
    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => FollowUpStatus::Skipped,
            'skip_reason' => $reason,
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => FollowUpStatus::Failed,
            'skip_reason' => $reason,
        ]);
    }

    /**
     * Quiet hours and holidays delay a message, they do not cancel it. The row
     * stays pending and moves to the next working moment.
     */
    public function deferTo(\DateTimeInterface $moment): void
    {
        $this->update(['scheduled_at' => $moment]);
    }
}
