<?php

namespace App\Mail;

use App\Models\WeeklyReport;
use App\Models\Workspace;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly WeeklyReport $report,
        public readonly Workspace $workspace,
    ) {}

    public function envelope(): Envelope
    {
        $rate = $this->report->metric('headline.on_time_rate');

        // The number goes in the subject line. A manager scanning a phone
        // should learn the week's headline without opening anything.
        $headline = $rate === null
            ? 'بدون داده'
            : $rate.'٪ تکمیل به‌موقع';

        return new Envelope(
            subject: sprintf(
                'گزارش هفتگی %s — %s',
                $this->workspace->name,
                $headline,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.weekly-report',
            with: [
                'periodStart' => JalaliDate::format(CarbonImmutable::parse($this->report->period_start)),
                'periodEnd' => JalaliDate::format(CarbonImmutable::parse($this->report->period_end)),
            ],
        );
    }
}
