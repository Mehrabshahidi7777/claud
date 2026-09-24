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

    {{-- The money the report exists to surface. Kept to the figures a chief
         executive acts on rather than a second copy of the finance page —
         the button below goes to the full one. Absent entirely on a plan
         that has no money side. --}}
    @php $money = $report->metric('money'); @endphp

    @if ($money && ($money['outstanding'] > 0 || $money['spent'] > 0))
        <tr>
            <td style="padding:16px 24px 8px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#0f172a;">
                    پول <span style="font-weight:normal;font-size:11px;color:#94a3b8;">(ریال)</span>
                </p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                    <tr>
                        <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#334155;">وصول‌نشده</td>
                        <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#0f172a;text-align:left;white-space:nowrap;">
                            {{ number_format($money['outstanding']) }}
                        </td>
                    </tr>
                    @if ($money['overdue'] > 0)
                        <tr>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#b45309;">از سررسید گذشته</td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#b45309;text-align:left;white-space:nowrap;">
                                {{ number_format($money['overdue']) }}
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#334155;">وصول‌شده این هفته</td>
                        <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#15803d;text-align:left;white-space:nowrap;">
                            {{ number_format($money['collected']) }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#334155;">هزینه این هفته</td>
                        <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#0f172a;text-align:left;white-space:nowrap;">
                            {{ number_format($money['spent']) }}
                        </td>
                    </tr>
                </table>

                @if ($money['unchased'] > 0)
                    <p style="margin:8px 0 0;font-size:12px;color:#b45309;">
                        {{ $money['unchased'] }} فقره‌ی معوق هنوز هیچ پیگیری‌کننده‌ای ندارد.
                    </p>
                @endif
            </td>
        </tr>
    @endif

    @php $contracts = $report->metric('contracts'); @endphp

    @if ($contracts && $contracts['expired'] > 0)
        <tr>
            <td style="padding:16px 24px 8px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#0f172a;">
                    قرارداد و مجوز منقضی
                </p>

                @if ($contracts['serious'] > 0)
                    <p style="margin:0 0 8px;font-size:12px;color:#b91c1c;">
                        {{ $contracts['serious'] }} موردش از آن‌هایی است که رها کردنش گران تمام می‌شود.
                    </p>
                @endif

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                    @foreach ($contracts['soonest'] as $row)
                        <tr>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#334155;">
                                {{ $row['title'] }}
                                <span style="color:#94a3b8;font-size:11px;">— {{ $row['party'] }}</span>
                            </td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;text-align:left;white-space:nowrap;color:{{ $row['days'] < 0 ? '#b91c1c' : '#b45309' }};">
                                @if ($row['days'] < 0)
                                    {{ abs($row['days']) }} روز گذشته
                                @else
                                    {{ $row['days'] }} روز مانده
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    @endif

    @php $recurring = $report->metric('recurring'); @endphp

    @if ($recurring && $recurring['overdue'] > 0)
        <tr>
            <td style="padding:16px 24px 8px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#0f172a;">
                    کارهای دوره‌ای عقب‌افتاده
                </p>

                @if ($recurring['value_at_risk'] > 0)
                    <p style="margin:0 0 8px;font-size:12px;color:#b91c1c;">
                        حدود {{ number_format($recurring['value_at_risk']) }} ریال درآمد سرویس که هنوز فاکتور نشده.
                    </p>
                @endif

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                    @foreach ($recurring['worst'] as $row)
                        <tr>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#334155;">
                                {{ $row['title'] }}
                                @if ($row['customer'])
                                    <span style="color:#94a3b8;font-size:11px;">— {{ $row['customer'] }}</span>
                                @endif
                            </td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;text-align:left;white-space:nowrap;color:#64748b;">
                                @if ($row['value']) {{ number_format($row['value']) }} @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    @endif

    @php $approvals = $report->metric('approvals'); @endphp

    @if ($approvals && $approvals['stale'] > 0)
        <tr>
            <td style="padding:16px 24px 8px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#0f172a;">
                    درخواست‌های معطل‌مانده
                </p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                    @foreach ($approvals['oldest'] as $row)
                        <tr>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;color:#334155;">
                                {{ $row['title'] }}
                                <span style="color:#94a3b8;font-size:11px;">— {{ $row['requester'] }}</span>
                            </td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;text-align:left;white-space:nowrap;color:#b45309;">
                                {{ $row['waiting_days'] }} روز
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
