@extends('layouts.app')

@section('title', 'گزارش')

@section('content')

@php use App\Support\JalaliDate; @endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <h1 class="text-lg font-bold">گزارش عملکرد</h1>

    <div class="ms-auto flex gap-1 text-sm">
        @foreach ([7 => 'هفته', 30 => 'ماه', 90 => 'سه ماه'] as $value => $label)
            <a href="{{ route('reports.index', ['days' => $value]) }}"
               class="rounded-lg px-3 py-1.5 {{ $days === $value ? 'bg-brand-700 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>

{{-- The three numbers from the design document. The first is what proves ROI
     to the customer; the other two are how we learn the engine has become a
     spam machine before they do. --}}
<div class="grid gap-3 sm:grid-cols-3">
    @php
        $tiles = [
            [
                'label' => 'تکمیل به‌موقع',
                'value' => $report['on_time_rate'],
                'hint' => 'روند صعودی، دلیل تمدید اشتراک',
                'good' => fn ($v) => $v >= 70,
            ],
            [
                'label' => 'پاسخ به پیامک پیگیری',
                'value' => $report['chase_response_rate'],
                'hint' => 'زیر ۶۰٪ یعنی متن یا زمان‌بندی غلط است',
                'good' => fn ($v) => $v >= 60,
            ],
            [
                'label' => 'کارهایی که به مدیر رسید',
                'value' => $report['escalation_ratio'],
                'hint' => 'بالای ۲۵٪ یعنی مدیر دارد اسپم می‌گیرد',
                'good' => fn ($v) => $v <= 25,
            ],
        ];
    @endphp

    @foreach ($tiles as $tile)
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">{{ $tile['label'] }}</p>

            @if ($tile['value'] === null)
                <p class="mt-2 text-2xl font-bold text-slate-300">—</p>
                <p class="mt-1 text-xs text-slate-400">داده‌ای در این بازه نیست</p>
            @else
                <p class="tabular mt-2 text-2xl font-bold {{ $tile['good']($tile['value']) ? 'text-emerald-600' : 'text-amber-600' }}">
                    {{ $tile['value'] }}٪
                </p>
                <p class="mt-1 text-xs text-slate-400">{{ $tile['hint'] }}</p>
            @endif
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="font-medium">کارهای عقب‌افتاده ({{ $report['overdue']->count() }})</h2>

        @if ($report['overdue']->isEmpty())
            <p class="mt-3 text-sm text-slate-500">هیچ کاری عقب نیست.</p>
        @else
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($report['overdue'] as $task)
                    <li class="flex items-start gap-2 py-2">
                        <span class="min-w-0 flex-1">{{ $task->title }}</span>
                        <span class="shrink-0 text-slate-500">{{ $task->assignee?->name ?? '—' }}</span>
                        <span class="tabular shrink-0 font-medium text-red-600">
                            {{ $task->hoursOverdue() }} ساعت
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="font-medium">مصرف پیامک</h2>

        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex justify-between">
                <dt class="text-slate-500">فرستاده‌شده در این بازه</dt>
                <dd class="tabular font-medium">{{ number_format($report['sms_sent_this_period']) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">مصرف کل دوره</dt>
                <dd class="tabular font-medium">{{ number_format($report['sms_used']) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">باقی‌مانده</dt>
                <dd class="tabular font-medium">{{ number_format($report['sms_remaining']) }}</dd>
            </div>
        </dl>

        <p class="mt-3 text-xs text-slate-400">
            شمارش بر حسب بخش پیامک است، نه تعداد پیام — چون همین را سرویس فاکتور می‌کند.
        </p>
    </div>
</div>

<div class="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
    <h2 class="font-medium">به تفکیک نفر</h2>
    <p class="mt-1 text-xs text-slate-400">
        در یک عدد کل، کسی که هیچ‌وقت دیر نمی‌کند و کسی که همیشه دیر می‌کند یکسان دیده می‌شوند.
    </p>

    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-start text-xs text-slate-500">
                <tr class="border-b border-slate-200">
                    <th class="py-2 text-start font-medium">نام</th>
                    <th class="py-2 text-start font-medium">کل</th>
                    <th class="py-2 text-start font-medium">بسته‌شده</th>
                    <th class="py-2 text-start font-medium">به‌موقع</th>
                    <th class="py-2 text-start font-medium">عقب‌افتاده</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($report['by_member'] as $row)
                    <tr>
                        <td class="py-2">
                            {{ $row['name'] ?: '—' }}
                            @if ($row['opted_out'])
                                <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                                    پیامک قطع
                                </span>
                            @endif
                        </td>
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
    بازه: <span class="tabular">{{ JalaliDate::format($report['period_from']) }}</span>
    تا <span class="tabular">{{ JalaliDate::format($report['period_to']) }}</span>
</p>

@endsection
