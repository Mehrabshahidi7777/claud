@extends('layouts.app')

@section('title', 'مالی')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $change = $spending['previous_spent'] > 0
        ? round(($spending['spent'] - $spending['previous_spent']) / $spending['previous_spent'] * 100)
        : null;
@endphp

<div class="mx-auto max-w-4xl">

    <div class="mb-4 flex flex-wrap items-baseline gap-3">
        <h1 class="text-lg font-bold">مالی</h1>

        <nav class="flex gap-1 text-sm">
            <a href="{{ route('finance.expenses') }}" class="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100">هزینه‌ها</a>
            <a href="{{ route('finance.receivables') }}" class="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100">مطالبات</a>
        </nav>

        <span class="tabular ms-auto text-sm text-slate-500">
            {{ JalaliDate::format($spending['from']) }} تا {{ JalaliDate::format($spending['to']) }}
        </span>
    </div>

    {{-- Two figures, because they are the only two a manager acts on: what
         went out, and what has not come in. --}}
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">هزینه‌ی این دوره</p>
            <p class="tabular mt-1 text-2xl font-bold">{{ number_format($spending['spent']) }}
                <span class="text-sm font-normal text-slate-400">ریال</span>
            </p>

            @if ($change !== null)
                <p class="tabular mt-1 text-xs {{ $change > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                    {{ $change > 0 ? '▲' : '▼' }} {{ abs($change) }}٪ نسبت به دوره‌ی قبل
                </p>
            @endif
        </div>

        <div class="rounded-2xl border bg-white p-5 {{ $receivables['overdue'] > 0 ? 'border-amber-300' : 'border-slate-200' }}">
            <p class="text-sm text-slate-500">مطالبات وصول‌نشده</p>
            <p class="tabular mt-1 text-2xl font-bold">{{ number_format($receivables['total']) }}
                <span class="text-sm font-normal text-slate-400">ریال</span>
            </p>

            @if ($receivables['overdue'] > 0)
                <p class="tabular mt-1 text-xs text-amber-700">
                    {{ number_format($receivables['overdue']) }} ریال از سررسید گذشته
                </p>
            @endif
        </div>
    </div>

    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="font-medium">هزینه به تفکیک دسته</h2>

        @if ($spending['by_category']->isEmpty())
            <p class="mt-2 text-sm text-slate-500">در این دوره هزینه‌ای ثبت نشده.</p>
        @else
            <div class="mt-3 space-y-2.5">
                @foreach ($spending['by_category'] as $row)
                    <div>
                        <div class="flex flex-wrap items-baseline gap-2 text-sm">
                            <span>{{ $row['category']->label() }}</span>
                            <span class="text-xs text-slate-400">{{ $row['entries'] }} مورد</span>
                            <span class="tabular ms-auto font-medium">{{ number_format($row['total']) }}</span>
                            <span class="tabular w-10 text-end text-xs text-slate-400">{{ $row['share'] }}٪</span>
                        </div>

                        {{-- A bar drawn to the same scale as the percentage
                             beside it, so the two can never disagree. --}}
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-brand-600" style="width: {{ max(1, $row['share']) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-baseline gap-2">
            <h2 class="font-medium">سنّ مطالبات</h2>
            <a href="{{ route('finance.receivables') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">
                همه‌ی مطالبات
            </a>
        </div>

        <div class="mt-3 grid gap-2 sm:grid-cols-4">
            @foreach ($receivables['buckets'] as $bucket)
                @php
                    $tone = match ($bucket['tone']) {
                        'amber' => 'border-amber-200 bg-amber-50',
                        'orange' => 'border-orange-200 bg-orange-50',
                        'red' => 'border-red-200 bg-red-50',
                        default => 'border-slate-200 bg-slate-50',
                    };
                @endphp

                <div class="rounded-xl border p-3 {{ $tone }}">
                    <p class="text-xs text-slate-600">{{ $bucket['label'] }}</p>
                    <p class="tabular mt-1 font-bold">{{ number_format($bucket['total']) }}</p>
                    <p class="tabular text-xs text-slate-500">{{ $bucket['count'] }} فقره</p>
                </div>
            @endforeach
        </div>

        @if ($receivables['worst']->isNotEmpty())
            <ul class="mt-4 divide-y divide-slate-100 text-sm">
                @foreach ($receivables['worst'] as $receivable)
                    <li class="flex flex-wrap items-center gap-2 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ $receivable->customer_name }}</span>
                        <span class="tabular text-slate-500">{{ number_format($receivable->outstanding()) }}</span>
                        <span class="tabular rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">
                            {{ $receivable->daysOverdue() }} روز
                        </span>

                        {{-- Whether the engine has already picked it up. An
                             overdue invoice with nobody chasing it is the
                             thing this whole module exists to surface. --}}
                        @if ($receivable->task)
                            <a href="{{ route('tasks.show', $receivable->task) }}"
                               class="text-xs text-emerald-700 hover:underline">در حال پیگیری</a>
                        @else
                            <span class="text-xs text-slate-400">هنوز پیگیری نشده</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- The number that exists only because approvals and expenses live in
         the same system: money the company said yes to, with no receipt. --}}
    <div class="mt-4 rounded-2xl border p-5 {{ $unrecorded->isEmpty() ? 'border-slate-200 bg-white' : 'border-amber-300 bg-white' }}">
        <h2 class="font-medium">تأیید شده، ثبت نشده</h2>
        <p class="mt-1 text-xs text-slate-500">
            خریدهایی که تأیید شده‌اند ولی بیش از دو هفته است هزینه‌ای برایشان ثبت نشده.
        </p>

        @if ($unrecorded->isEmpty())
            <p class="mt-3 text-sm text-emerald-700">همه‌ی تأییدیه‌ها فاکتور خورده‌اند.</p>
        @else
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($unrecorded as $approval)
                    <li class="flex flex-wrap items-center gap-2 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ $approval->title }}</span>
                        <span class="text-slate-500">{{ $approval->requester?->name }}</span>
                        <span class="tabular">{{ number_format($approval->amount) }}</span>
                        <span class="tabular text-xs text-slate-400">
                            {{ JalaliDate::format(CarbonImmutable::parse($approval->decided_at)) }}
                        </span>
                    </li>
                @endforeach
            </ul>

            <a href="{{ route('finance.expenses') }}"
               class="mt-3 inline-block rounded-lg bg-brand-700 px-3 py-1.5 text-xs text-white hover:bg-brand-800">
                ثبت هزینه برایشان
            </a>
        @endif
    </div>
</div>

@endsection
