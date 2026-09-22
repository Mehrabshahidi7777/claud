@extends('layouts.app')

@section('title', 'درخواست‌ها')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto max-w-3xl">

    <div class="mb-4 flex items-baseline gap-3">
        <h1 class="text-lg font-bold">درخواست‌ها</h1>
        <a href="{{ route('approvals.create') }}"
           class="ms-auto rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            درخواست جدید
        </a>
    </div>

    {{-- What is waiting on me, first. A pending request is somebody blocked. --}}
    <h2 class="mb-2 text-sm font-medium text-slate-500">منتظر تصمیم شما ({{ $awaitingMe->count() }})</h2>

    @if ($awaitingMe->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center text-sm text-slate-500">
            چیزی منتظر تصمیم شما نیست.
        </div>
    @else
        <div class="space-y-3">
            @foreach ($awaitingMe as $approval)
                <div class="rounded-2xl border border-amber-200 bg-white p-4">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                            {{ $approval->type->label() }}
                        </span>
                        <span class="font-medium">{{ $approval->title }}</span>
                        <span class="text-sm text-slate-500">{{ $approval->requester?->name }}</span>
                    </div>

                    <div class="tabular mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                        @if ($approval->starts_on)
                            <span>
                                {{ JalaliDate::format(CarbonImmutable::parse($approval->starts_on)) }}
                                تا
                                {{ JalaliDate::format(CarbonImmutable::parse($approval->ends_on)) }}
                                ({{ $approval->dayCount() }} روز)
                            </span>
                        @endif

                        @if ($approval->amount)
                            <span>{{ number_format($approval->amount) }} ریال</span>
                        @endif
                    </div>

                    @if ($approval->reason)
                        <p class="mt-2 text-sm leading-7 text-slate-600">{{ $approval->reason }}</p>
                    @endif

                    {{-- The number that makes this a decision rather than a
                         rubber stamp: what falls over while they are away. --}}
                    @php $clash = $conflicts[$approval->id] ?? collect(); @endphp

                    @if ($clash->isNotEmpty())
                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <p class="text-xs font-medium text-amber-900">
                                {{ $clash->count() }} تسک باز در همین بازه سررسید دارد:
                            </p>
                            <ul class="tabular mt-1.5 space-y-1 text-xs text-amber-900">
                                @foreach ($clash as $task)
                                    <li class="flex gap-2">
                                        <span class="min-w-0 flex-1 truncate">{{ $task->title }}</span>
                                        <span>{{ JalaliDate::format(CarbonImmutable::parse($task->due_at)) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('approvals.decide', $approval) }}"
                          class="mt-3 flex flex-wrap items-center gap-2">
                        @csrf

                        <input type="text" name="note" maxlength="255" placeholder="توضیح (اختیاری)"
                               class="min-w-40 flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs focus:border-slate-900 focus:outline-none">

                        <button type="submit" name="decision" value="approve"
                                class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
                            تأیید
                        </button>
                        <button type="submit" name="decision" value="reject"
                                class="rounded-lg border border-slate-300 px-4 py-1.5 text-xs text-slate-700 hover:bg-slate-50">
                            رد
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="mt-8 mb-2 text-sm font-medium text-slate-500">درخواست‌های من</h2>

    @if ($mine->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center text-sm text-slate-500">
            هنوز درخواستی نداده‌اید.
        </div>
    @else
        <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white">
            @foreach ($mine as $approval)
                <div class="flex flex-wrap items-center gap-2 p-4">
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                        {{ $approval->type->label() }}
                    </span>
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $approval->title }}</span>

                    <span class="rounded-full px-2 py-0.5 text-xs {{ $approval->status->badgeClasses() }}">
                        {{ $approval->status->label() }}
                    </span>

                    @if ($approval->isCancellableBy(auth()->user()))
                        <form method="POST" action="{{ route('approvals.cancel', $approval) }}">
                            @csrf
                            <button class="text-xs text-slate-400 hover:text-slate-900">لغو</button>
                        </form>
                    @endif

                    @if ($approval->decision_note)
                        <p class="w-full text-xs text-slate-500">توضیح: {{ $approval->decision_note }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

@endsection
