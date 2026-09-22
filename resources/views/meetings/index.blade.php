@extends('layouts.app')

@section('title', 'جلسات')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto max-w-3xl">

    <div class="mb-4 flex items-baseline gap-3">
        <h1 class="text-lg font-bold">جلسات</h1>
        <a href="{{ route('meetings.create') }}"
           class="ms-auto rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            ثبت جلسه
        </a>
    </div>

    @if ($meetings->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <p class="text-slate-600">هنوز جلسه‌ای ثبت نشده.</p>
            <p class="mt-2 text-xs text-slate-400">
                متن جلسه را بچسبانید تا اقدام‌هایش استخراج شود — و شش ماه بعد هنوز
                بدانید چه تصمیمی گرفته شد.
            </p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($meetings as $meeting)
                <a href="{{ route('meetings.show', $meeting) }}"
                   class="block rounded-2xl border border-slate-200 bg-white p-4 hover:border-slate-400">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="font-medium">{{ $meeting->title }}</span>
                        <span class="tabular text-sm text-slate-500">
                            {{ JalaliDate::format(CarbonImmutable::parse($meeting->held_at)) }}
                        </span>

                        <span class="tabular ms-auto text-xs text-slate-400">
                            {{ $meeting->tasks_count }} تسک
                        </span>
                    </div>

                    @if ($meeting->summary)
                        <p class="mt-1.5 line-clamp-2 text-sm text-slate-500">{{ $meeting->summary }}</p>
                    @endif
                </a>
            @endforeach
        </div>

        <div class="mt-4">{{ $meetings->links() }}</div>
    @endif
</div>

@endsection
