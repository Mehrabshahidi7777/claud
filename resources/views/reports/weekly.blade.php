@extends('layouts.app')

@section('title', 'گزارش هفتگی')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto max-w-3xl">

    <div class="mb-4 flex flex-wrap items-baseline gap-2">
        <h1 class="text-lg font-bold">گزارش هفتگی</h1>
        <span class="tabular text-sm text-slate-500">
            {{ JalaliDate::format(CarbonImmutable::parse($report->period_start)) }}
            تا
            {{ JalaliDate::format(CarbonImmutable::parse($report->period_end)) }}
        </span>

        <a href="{{ route('reports.weekly.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">
            گزارش‌های قبلی
        </a>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <p class="text-[15px] leading-9 text-slate-700">{{ $report->narrative }}</p>

        @unless ($report->narrative_from_ai)
            {{-- Said plainly rather than hidden. A manager who knows the model
                 was down reads the paragraph for what it is, and it is still
                 built from the same numbers. --}}
            <p class="mt-3 text-xs text-slate-400">
                این خلاصه بدون دستیار هوشمند و مستقیماً از روی اعداد نوشته شده است.
            </p>
        @endunless
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        @php
            $tiles = [
                ['تکمیل به‌موقع', 'headline.on_time_rate', 'change.on_time_rate', true],
                ['پاسخ به پیامک پیگیری', 'headline.chase_response_rate', 'change.chase_response_rate', true],
                ['کارهایی که به مدیر رسید', 'headline.escalation_ratio', 'change.escalation_ratio', false],
            ];
        @endphp

        @foreach ($tiles as [$label, $valueKey, $changeKey, $higherIsBetter])
            @php
                $value = $report->metric($valueKey);
                $change = $report->metric($changeKey);
                $improved = $change === null ? null : ($higherIsBetter ? $change > 0 : $change < 0);
            @endphp

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-sm text-slate-500">{{ $label }}</p>

                <p class="tabular mt-2 text-2xl font-bold {{ $value === null ? 'text-slate-300' : 'text-slate-900' }}">
                    {{ $value === null ? '—' : $value.'٪' }}
                </p>

                @if ($change !== null && $change != 0)
                    <p class="tabular mt-1 text-xs {{ $improved ? 'text-emerald-600' : 'text-red-600' }}">
                        {{ $change > 0 ? '▲' : '▼' }} {{ abs($change) }} واحد نسبت به هفته قبل
                    </p>
                @elseif ($change !== null)
                    <p class="mt-1 text-xs text-slate-400">بدون تغییر</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-4">
        @foreach ([
            'ثبت‌شده' => 'counts.created',
            'بسته‌شده' => 'counts.closed',
            'عقب‌افتاده' => 'counts.overdue_now',
            'به مدیر رسید' => 'counts.escalated',
        ] as $label => $key)
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="tabular mt-1 text-lg font-bold">{{ $report->metric($key, 0) }}</p>
            </div>
        @endforeach
    </div>

    @if (count($report->metric('overdue', [])) > 0)
        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">عقب‌افتاده</h2>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($report->metric('overdue', []) as $task)
                    <li class="flex items-start gap-2 py-2">
                        <span class="min-w-0 flex-1">{{ $task['title'] }}</span>
                        <span class="shrink-0 text-slate-500">{{ $task['assignee'] ?? '—' }}</span>
                        <span class="tabular shrink-0 font-medium text-red-600">
                            {{ $task['hours_overdue'] }} ساعت
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (count($report->metric('at_risk', [])) > 0)
        <div class="mt-4 rounded-2xl border border-amber-200 bg-white p-4">
            <h2 class="font-medium">سررسید تا سه روز آینده</h2>
            <p class="mt-1 text-xs text-slate-400">
                هنوز دیر نشده — همین‌ها هستند که هفته‌ی بعد عقب‌افتاده می‌شوند.
            </p>

            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($report->metric('at_risk', []) as $task)
                    <li class="flex flex-wrap items-start gap-2 py-2">
                        <span class="min-w-0 flex-1">
                            {{ $task['title'] }}
                            @if ($task['assignee_already_overdue'])
                                <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                                    این نفر کار عقب‌افتاده دارد
                                </span>
                            @endif
                        </span>
                        <span class="shrink-0 text-slate-500">{{ $task['assignee'] ?? '—' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif


    {{-- The other engines. Each section exists only where the workspace's
         plan has it, so a household never meets an empty receivables table
         reading as a broken feature. Ordered by what costs most. --}}

    @php $money = $report->metric('money'); @endphp

    @if ($money && $canSeeFinance)
        <div class="mt-4 rounded-2xl border bg-white p-4 {{ $money['unchased'] > 0 ? 'border-amber-300' : 'border-slate-200' }}">
            <div class="flex flex-wrap items-baseline gap-2">
                <h2 class="font-medium">پول</h2>
                <span class="tabular ms-auto text-xs text-slate-400">همه‌ی مبالغ به ریال</span>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-4">
                @foreach ([
                    ['وصول‌نشده', $money['outstanding'], 'text-slate-900'],
                    ['از سررسید گذشته', $money['overdue'], $money['overdue'] > 0 ? 'text-amber-700' : 'text-slate-900'],
                    ['وصول‌شده این هفته', $money['collected'], 'text-emerald-700'],
                    ['هزینه این هفته', $money['spent'], 'text-slate-900'],
                ] as [$label, $value, $tone])
                    <div>
                        <p class="text-xs text-slate-500">{{ $label }}</p>
                        <p class="tabular mt-0.5 font-bold {{ $tone }}">{{ number_format($value) }}</p>
                    </div>
                @endforeach
            </div>

            @if ($money['unchased'] > 0)
                <p class="tabular mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    {{ $money['unchased'] }} فقره‌ی معوق هنوز هیچ پیگیری‌کننده‌ای ندارد.
                </p>
            @endif

            @if (! empty($money['worst']))
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($money['worst'] as $row)
                        <li class="flex flex-wrap items-baseline gap-2 py-2">
                            <span class="min-w-0 flex-1 truncate">{{ $row['customer'] }}</span>
                            <span class="tabular text-slate-500">{{ number_format($row['amount']) }}</span>
                            <span class="tabular rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">
                                {{ $row['days'] }} روز
                            </span>
                            <span class="text-xs {{ $row['chased'] ? 'text-emerald-700' : 'text-slate-400' }}">
                                {{ $row['chased'] ? 'در حال پیگیری' : 'بدون پیگیر' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @php $contracts = $report->metric('contracts'); @endphp

    @if ($contracts && $canSeeContracts && ($contracts['expired'] > 0 || $contracts['expiring_soon'] > 0))
        <div class="mt-4 rounded-2xl border bg-white p-4 {{ $contracts['serious'] > 0 ? 'border-red-300' : 'border-slate-200' }}">
            <h2 class="font-medium">قراردادها و مجوزها</h2>

            <p class="tabular mt-1 text-sm text-slate-500">
                {{ $contracts['expired'] }} منقضی، {{ $contracts['expiring_soon'] }} نزدیک انقضا
            </p>

            @if ($contracts['serious'] > 0)
                <p class="tabular mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-900">
                    {{ $contracts['serious'] }} موردش از آن‌هایی است که رها کردنش گران تمام می‌شود.
                </p>
            @endif

            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($contracts['soonest'] as $row)
                    <li class="flex flex-wrap items-baseline gap-2 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ $row['title'] }}</span>
                        <span class="text-slate-500">{{ $row['party'] }}</span>
                        <span class="tabular rounded-full px-2 py-0.5 text-xs {{ $row['days'] < 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                            @if ($row['days'] < 0)
                                {{ abs($row['days']) }} روز گذشته
                            @else
                                {{ $row['days'] }} روز مانده
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @php $recurring = $report->metric('recurring'); @endphp

    @if ($recurring && ($recurring['overdue'] > 0 || $recurring['due_soon'] > 0))
        <div class="mt-4 rounded-2xl border bg-white p-4 {{ $recurring['overdue'] > 0 ? 'border-amber-300' : 'border-slate-200' }}">
            <h2 class="font-medium">کارهای دوره‌ای</h2>

            <p class="tabular mt-1 text-sm text-slate-500">
                {{ $recurring['overdue'] }} از تاریخ گذشته، {{ $recurring['due_soon'] }} در نوبت
            </p>

            @if ($recurring['value_at_risk'] > 0)
                <p class="tabular mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-900">
                    حدود {{ number_format($recurring['value_at_risk']) }} ریال درآمد سرویس که هنوز فاکتور نشده.
                </p>
            @endif

            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($recurring['worst'] as $row)
                    <li class="flex flex-wrap items-baseline gap-2 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ $row['title'] }}</span>
                        @if ($row['customer'])
                            <span class="text-slate-500">{{ $row['customer'] }}</span>
                        @endif
                        @if ($row['value'])
                            <span class="tabular text-slate-500">{{ number_format($row['value']) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @php $approvals = $report->metric('approvals'); @endphp

    @if ($approvals && $approvals['pending'] > 0)
        <div class="mt-4 rounded-2xl border bg-white p-4 {{ $approvals['stale'] > 0 ? 'border-amber-300' : 'border-slate-200' }}">
            <h2 class="font-medium">درخواست‌های بی‌پاسخ</h2>

            <p class="tabular mt-1 text-sm text-slate-500">
                {{ $approvals['pending'] }} منتظر تصمیم،
                {{ $approvals['stale'] }} بیش از یک هفته معطل
            </p>

            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($approvals['oldest'] as $row)
                    <li class="flex flex-wrap items-baseline gap-2 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ $row['title'] }}</span>
                        <span class="text-slate-500">{{ $row['requester'] }}</span>
                        <span class="tabular text-xs text-slate-400">{{ $row['waiting_days'] }} روز</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="font-medium">به تفکیک نفر</h2>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-500">
                    <tr class="border-b border-slate-200">
                        <th class="py-2 text-start font-medium">نام</th>
                        <th class="py-2 text-start font-medium">کل</th>
                        <th class="py-2 text-start font-medium">بسته‌شده</th>
                        <th class="py-2 text-start font-medium">به‌موقع</th>
                        <th class="py-2 text-start font-medium">عقب‌افتاده</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($report->metric('by_member', []) as $row)
                        <tr>
                            <td class="py-2">{{ $row['name'] ?: '—' }}</td>
                            <td class="tabular py-2">{{ $row['total'] }}</td>
                            <td class="tabular py-2">{{ $row['closed'] }}</td>
                            <td class="tabular py-2 text-emerald-700">{{ $row['on_time'] }}</td>
                            <td class="tabular py-2 {{ $row['overdue'] > 0 ? 'font-medium text-red-600' : '' }}">
                                {{ $row['overdue'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-slate-400">
        ساخته‌شده در {{ JalaliDate::format(CarbonImmutable::parse($report->created_at)) }}.
        @if ($report->emailed_at) ایمیل شد. @endif
        @if ($report->sms_notified_at) پیامک شد. @endif
    </p>
</div>

@endsection
