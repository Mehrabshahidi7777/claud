@extends('layouts.app')

@section('title', 'گزارش‌های هفتگی')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto max-w-3xl">
    <div class="mb-4 flex items-baseline gap-2">
        <h1 class="text-lg font-bold">گزارش‌های هفتگی</h1>
        <a href="{{ route('reports.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">
            گزارش زنده
        </a>
    </div>

    @if ($reports->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <p class="text-slate-600">هنوز گزارشی ساخته نشده.</p>
            <p class="mt-2 text-xs text-slate-400">
                گزارش هر شنبه ساعت ۸ صبح خودکار ساخته و فرستاده می‌شود.
            </p>
        </div>
    @else
        {{-- One week's number means little; six weeks of them is the argument
             for renewing the subscription. --}}
        <div class="space-y-2">
            @foreach ($reports as $report)
                @php $rate = $report->metric('headline.on_time_rate'); @endphp

                <a href="{{ $report->url() }}"
                   class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 hover:border-slate-400">
                    <span class="tabular text-sm text-slate-500">
                        {{ JalaliDate::format(CarbonImmutable::parse($report->period_start)) }}
                        تا
                        {{ JalaliDate::format(CarbonImmutable::parse($report->period_end)) }}
                    </span>

                    <span class="tabular ms-auto text-lg font-bold {{ $rate === null ? 'text-slate-300' : 'text-slate-900' }}">
                        {{ $rate === null ? '—' : $rate.'٪' }}
                    </span>

                    <span class="tabular shrink-0 text-sm {{ $report->metric('counts.overdue_now', 0) > 0 ? 'text-red-600' : 'text-slate-400' }}">
                        {{ $report->metric('counts.overdue_now', 0) }} عقب‌افتاده
                    </span>
                </a>
            @endforeach
        </div>

        <div class="mt-4">{{ $reports->links() }}</div>
    @endif
</div>

@endsection
