<?php

namespace App\Services;

use App\Contracts\SmsDriver;
use App\Enums\WorkspaceRole;
use App\Mail\WeeklyReportMail;
use App\Models\Activity;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\Workspace;
use App\Sms\PatternMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Generates and delivers the fifth rung of the ladder.
 *
 * The other four rungs chase a single task. This one is the only thing the
 * chief executive sees, which makes it the rung that gets the subscription
 * renewed — so it has to arrive even when the model is down, the mail server
 * is refusing, or the sweep runs twice.
 */
class WeeklyReportDispatcher
{
    public function __construct(
        private readonly WeeklyReportComposer $composer,
        private readonly SmsDriver $sms,
    ) {}

    /**
     * Build and send this workspace's report for the period ending now.
     * Returns null when one already exists for the period.
     */
    public function dispatchFor(Workspace $workspace, ?CarbonImmutable $now = null): ?WeeklyReport
    {
        $now ??= CarbonImmutable::now();
        $periodEnd = $now->setTimezone($workspace->timezone());
        $periodStart = $periodEnd->subDays(7);

        $report = $this->generate($workspace, $periodStart, $periodEnd);

        if ($report === null) {
            return null;
        }

        $this->deliver($workspace, $report);

        Activity::record($report, 'weekly_report.sent', $workspace->id, properties: [
            'period_start' => $report->period_start->toDateString(),
            'from_ai' => $report->narrative_from_ai,
        ]);

        return $report;
    }

    /**
     * The snapshot is frozen here and never recomputed. A figure that moves
     * between Saturday and Tuesday is a figure the manager stops believing.
     */
    private function generate(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): ?WeeklyReport
    {
        $snapshot = (new ReportBuilder($workspace))->snapshot($from, $to);
        $composed = $this->composer->compose($snapshot, $workspace);

        try {
            return WeeklyReport::create([
                'workspace_id' => $workspace->id,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'metrics' => $snapshot,
                'narrative' => $composed['narrative'],
                'narrative_from_ai' => $composed['from_ai'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another run got there first. The unique index on
            // (workspace_id, period_start) is what makes an hourly sweep safe
            // without a lock that can be lost.
            return null;
        }
    }

    /**
     * Email first, then an SMS carrying the headline and the link.
     *
     * The SMS is not a nicety. Email deliverability to Iranian inboxes is
     * unreliable enough that a report which exists only in an inbox is a
     * report that half the customers never read.
     */
    private function deliver(Workspace $workspace, WeeklyReport $report): void
    {
        $recipients = $this->recipients($workspace);

        $withEmail = $recipients->filter(fn (User $user) => filled($user->email));

        if ($withEmail->isNotEmpty()) {
            try {
                Mail::to($withEmail->all())->send(new WeeklyReportMail($report, $workspace));
                $report->update(['emailed_at' => now()]);
            } catch (Throwable $e) {
                // A refusing mail server must not cost us the SMS as well.
                report($e);
            }
        }

        $this->notifyBySms($workspace, $report, $recipients);
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function notifyBySms(Workspace $workspace, WeeklyReport $report, $recipients): void
    {
        if (! $workspace->sms_enabled || ! $workspace->hasSmsCredit()) {
            return;
        }

        $sent = false;

        foreach ($recipients as $user) {
            if ($user->hasOptedOutOfSms()) {
                continue;
            }

            $result = $this->sms->send(PatternMessage::make($user->phone, 'weekly_report', [
                'rate' => $this->headlineForSms($report),
                'overdue' => (string) $report->metric('counts.overdue_now', 0),
            ]));

            $sent = $sent || $result->successful;
        }

        if ($sent) {
            $report->update(['sms_notified_at' => now()]);
            $workspace->consumeSmsCredit($recipients->count());
        }
    }

    /**
     * The one figure worth seventy characters. "—" when there is nothing to
     * report, which is honest and still fits.
     */
    private function headlineForSms(WeeklyReport $report): string
    {
        $rate = $report->metric('headline.on_time_rate');

        return $rate === null ? '—' : (string) $rate;
    }

    /**
     * Owners and admins. A report naming who is behind is not something to
     * send to everyone it names.
     *
     * @return Collection<int, User>
     */
    private function recipients(Workspace $workspace)
    {
        return $workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->whereNull('workspace_user.deactivated_at')
            ->get();
    }
}
