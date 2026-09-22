{{--
    Email clients strip <style> blocks and ignore most modern CSS, so every
    rule here is inline and the layout is tables. It is not how the rest of
    the application is built, and it is the only thing that renders the same
    in Outlook, Gmail and the Iranian webmail clients customers actually use.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>گزارش هفتگی</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Tahoma,Arial,sans-serif;direction:rtl;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:24px 12px;">
<tr><td align="center">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;">

    <tr>
        <td style="padding:24px 24px 8px;">
            <p style="margin:0;font-size:13px;color:#64748b;">گزارش هفتگی</p>
            <h1 style="margin:4px 0 0;font-size:20px;color:#0f172a;">{{ $workspace->name }}</h1>
            <p style="margin:4px 0 0;font-size:12px;color:#94a3b8;">
                {{ $periodStart }} تا {{ $periodEnd }}
            </p>
        </td>
    </tr>

    {{-- The paragraph. Written by the local model where one is configured and
         from the numbers where it is not — the reader cannot tell. --}}
    <tr>
        <td style="padding:16px 24px;">
            <div style="background-color:#f8fafc;border-radius:8px;padding:16px;font-size:14px;line-height:2;color:#334155;">
                {{ $report->narrative }}
            </div>
        </td>
    </tr>

    <tr>
        <td style="padding:0 24px 8px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    @php
                        $tiles = [
                            ['تکمیل به‌موقع', $report->metric('headline.on_time_rate'), $report->metric('change.on_time_rate'), true],
                            ['پاسخ به پیامک', $report->metric('headline.chase_response_rate'), $report->metric('change.chase_response_rate'), true],
                            ['نسبت تشدید', $report->metric('headline.escalation_ratio'), $report->metric('change.escalation_ratio'), false],
                        ];
                    @endphp

                    @foreach ($tiles as [$label, $value, $change, $higherIsBetter])
                        <td width="33%" style="padding:6px;" valign="top">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                   style="background-color:#f8fafc;border-radius:8px;">
                                <tr><td style="padding:12px;text-align:center;">
                                    <p style="margin:0;font-size:11px;color:#64748b;">{{ $label }}</p>
                                    <p style="margin:6px 0 0;font-size:22px;font-weight:bold;color:{{ $value === null ? '#cbd5e1' : '#0f172a' }};">
                                        {{ $value === null ? '—' : $value.'٪' }}
                                    </p>

                                    @if ($change !== null && $change != 0)
                                        @php
                                            // A rising escalation ratio is bad news; a rising
                                            // completion rate is good. The arrow follows the
                                            // meaning, not the sign.
                                            $improved = $higherIsBetter ? $change > 0 : $change < 0;
                                        @endphp
                                        <p style="margin:4px 0 0;font-size:11px;color:{{ $improved ? '#059669' : '#dc2626' }};">
                                            {{ $change > 0 ? '▲' : '▼' }} {{ abs($change) }} واحد
                                        </p>
                                    @endif
                                </td></tr>
                            </table>
                        </td>
                    @endforeach
                </tr>
            </table>
        </td>
    </tr>

    @if ($report->metric('counts.overdue_now', 0) > 0)
        <tr>
            <td style="padding:8px 24px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#0f172a;">
                    عقب‌افتاده ({{ $report->metric('counts.overdue_now') }})
                </p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                    @foreach ($report->metric('overdue', []) as $task)
                        <tr>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#334155;">
                                {{ $task['title'] }}
                            </td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#64748b;white-space:nowrap;">
                                {{ $task['assignee'] ?? '—' }}
                            </td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#dc2626;white-space:nowrap;text-align:left;">
                                {{ $task['hours_overdue'] }} ساعت
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    @endif

    {{-- Work that has not failed yet. A report listing only what already went
         wrong arrives too late to be acted on. --}}
    @if (count($report->metric('at_risk', [])) > 0)
        <tr>
            <td style="padding:16px 24px 8px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#0f172a;">
                    سررسید تا سه روز آینده
                </p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                    @foreach ($report->metric('at_risk', []) as $task)
                        <tr>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#334155;">
                                {{ $task['title'] }}
                                @if ($task['assignee_already_overdue'])
                                    <span style="color:#b45309;font-size:11px;">— این نفر کار عقب‌افتاده دارد</span>
                                @endif
                            </td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#64748b;white-space:nowrap;text-align:left;">
                                {{ $task['assignee'] ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    @endif

    <tr>
        <td style="padding:20px 24px;" align="center">
            <a href="{{ $report->url() }}"
               style="display:inline-block;background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:14px;">
                دیدن گزارش کامل
            </a>
        </td>
    </tr>

    <tr>
        <td style="padding:12px 24px 24px;border-top:1px solid #f1f5f9;">
            <p style="margin:0;font-size:11px;color:#94a3b8;line-height:1.8;">
                این گزارش خودکار ساخته شده است. اعتبار پیامک باقی‌مانده:
                {{ $report->metric('sms.remaining', 0) }} از
                {{ $report->metric('sms.remaining', 0) + $report->metric('sms.used', 0) }}.
            </p>
        </td>
    </tr>

</table>

</td></tr>
</table>

</body>
</html>
