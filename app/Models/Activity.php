<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'user_id', 'subject_type', 'subject_id', 'event', 'properties'];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What this entry says out loud, in the timeline.
     *
     * Every event is spelled out rather than falling through to a generic
     * phrase: a history a manager cannot read is not a history, and this is
     * the page that answers "why is this on my list, and who moved it".
     */
    public function label(): string
    {
        return match ($this->event) {
            'task.created' => 'تسک ثبت شد',
            'task.created_from_meeting' => 'از روی صورتجلسه ثبت شد',
            'task.completed' => 'در سامانه بسته شد',
            'task.completed_by_sms' => 'با پاسخ پیامکی بسته شد',
            'task.cancelled' => 'لغو شد',
            'task.cancellation_requested' => 'مجری درخواست لغو داد',
            'task.rescheduled' => 'ددلاین جابه‌جا شد',
            'task.repeatedly_deferred' => 'چند بار پشت سر هم تأخیر خورد',
            'meeting.recorded' => 'صورتجلسه ثبت شد',
            'department.created' => 'بخش اضافه شد',
            'member.added' => 'عضو اضافه شد',
            'member.sms_resumed' => 'ارسال پیامک دوباره فعال شد',
            'user.sms_opted_out' => 'دریافت پیامک را قطع کرد',
            'approval.submitted' => 'درخواست ثبت شد',
            'approval.approved' => 'درخواست تأیید شد',
            'approval.rejected' => 'درخواست رد شد',
            'approval.cancelled' => 'درخواست لغو شد',
            'expense.recorded' => 'هزینه ثبت شد',
            'receivable.recorded' => 'مطالبه ثبت شد',
            'receivable.chase_raised' => 'برای وصولش تسک پیگیری ساخته شد',
            'receivable.payment_recorded' => 'دریافت ثبت شد',
            'contract.recorded' => 'قرارداد ثبت شد',
            'contract.renewal_raised' => 'برای تمدیدش تسک ساخته شد',
            'contract.renewed' => 'تمدید شد',
            'contract.ended' => 'خاتمه‌یافته ثبت شد',
            'recurrence.created' => 'کار دوره‌ای ثبت شد',
            'recurrence.occurrence_raised' => 'نوبت تازه‌اش تبدیل به تسک شد',
            'recurrence.cycle_completed' => 'یک نوبت انجام شد و نوبت بعدی زمان‌بندی شد',
            'settlement.recorded' => 'پرداخت بین اعضا ثبت شد',
            'receivables.imported' => 'فاکتورها از حسابداری درون‌ریزی شد',
            'invoice.paid' => 'فاکتور پرداخت شد',
            'weekly_report.sent' => 'گزارش هفتگی فرستاده شد',
            'subscription.granted_by_platform' => 'پشتیبانی پیگیر روز اشتراک اضافه کرد',
            'workspace.sms_updated_by_platform' => 'پشتیبانی پیگیر تنظیمات پیامک را تغییر داد',
            default => $this->event,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A null actor means the engine did it, which covers most of what ends up
     * here: chases sent, escalations raised, replies applied.
     */
    public static function record(
        Model $subject,
        string $event,
        int $workspaceId,
        ?int $userId = null,
        array $properties = [],
    ): self {
        return self::create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'properties' => $properties ?: null,
        ]);
    }
}
