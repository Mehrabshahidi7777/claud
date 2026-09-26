@extends('layouts.app')

@section('title', $meeting->title)

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto max-w-3xl">

    <div class="mb-4 flex flex-wrap items-baseline gap-2">
        <h1 class="text-lg font-bold">{{ $meeting->title }}</h1>
        <span class="tabular text-sm text-slate-500">
            {{ JalaliDate::format(CarbonImmutable::parse($meeting->held_at)) }}
        </span>
        <a href="{{ route('meetings.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">
            همه‌ی جلسات
        </a>
    </div>

    @if ($meeting->summary)
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-medium text-slate-500">خلاصه</h2>
            <p class="mt-2 leading-8 text-slate-700">{{ $meeting->summary }}</p>
        </div>
    @endif

    @if (! empty($meeting->decisions))
        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-medium text-slate-500">تصمیم‌ها</h2>
            <ul class="mt-2 space-y-1.5 text-sm text-slate-700">
                @foreach ($meeting->decisions as $decision)
                    <li class="flex gap-2">
                        <span class="text-slate-300">—</span>
                        <span>{{ $decision }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Drafts, not records. They survive one redirect and nothing is stored
         until the manager confirms an item. --}}
    @if (! empty($draftActions))
        <div class="mt-4 rounded-2xl border border-emerald-200 bg-white p-5">
            <h2 class="font-medium">اقدام‌های پیشنهادی ({{ count($draftActions) }})</h2>
            <p class="mt-1 text-xs text-slate-500">
                هرکدام را تأیید کنید تا کار شود و پیگیری‌اش زمان‌بندی شود. هرچه تأیید
                نکنید دور ریخته می‌شود.
            </p>

            <div class="mt-3 space-y-3">
                @foreach ($draftActions as $action)
                    <form method="POST" action="{{ route('meetings.actions.confirm', $meeting) }}"
                          class="rounded-xl border border-slate-200 p-3">
                        @csrf

                        <input type="hidden" name="title" value="{{ $action['title'] }}">
                        <input type="hidden" name="due_date" value="{{ $action['due_date'] ?? '' }}">
                        <input type="hidden" name="priority" value="{{ $action['priority'] }}">

                        <p class="text-sm font-medium">{{ $action['title'] }}</p>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <select name="assignee_id"
                                    class="rounded-lg border border-slate-300 px-2 py-1 text-xs">
                                <option value="">— بدون مسئول —</option>
                                @foreach ($members as $member)
                                    <option value="{{ $member->id }}"
                                            @selected(($action['assignee_id'] ?? null) === $member->id)>
                                        {{ $member->name }}
                                    </option>
                                @endforeach
                            </select>

                            <span class="tabular text-xs text-slate-500">
                                {{ $action['due_date'] ?? 'بدون مهلت' }}
                            </span>

                            <button type="submit"
                                    class="ms-auto rounded-lg bg-emerald-600 px-3 py-1.5 text-xs text-white hover:bg-emerald-700">
                                تأیید و ثبت
                            </button>
                        </div>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    @if ($meeting->tasks->isNotEmpty())
        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">کارهای این جلسه ({{ $meeting->tasks->count() }})</h2>

            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($meeting->tasks as $task)
                    <li class="flex flex-wrap items-center gap-2 py-2">
                        <span class="min-w-0 flex-1">{{ $task->title }}</span>
                        <span class="text-slate-500">{{ $task->assignee?->name ?? '—' }}</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                            {{ $task->status->label() }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-baseline gap-2">
            <h2 class="text-sm font-medium text-slate-500">متن اصلی جلسه</h2>

            <form method="POST" action="{{ route('meetings.reparse', $meeting) }}" class="ms-auto">
                @csrf
                <button class="text-xs text-slate-500 hover:text-slate-900">دوباره بخوان</button>
            </form>
        </div>

        @error('ai')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror

        <p class="mt-2 whitespace-pre-line text-sm leading-8 text-slate-600">{{ $meeting->notes }}</p>

        @unless ($meeting->processed_by_ai)
            <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                این جلسه بدون دستیار هوشمند ثبت شده است. متن کامل نگه داشته شده و
                هر وقت مدل در دسترس باشد می‌توانید دوباره امتحان کنید.
            </p>
        @endunless
    </div>
</div>

@endsection
