@extends('layouts.app')

@section('title', $task->title)

@section('content')

@php
    use App\Enums\FollowUpStatus;
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $isOverdue = $task->isOverdue();
    $due = $task->due_at ? CarbonImmutable::parse($task->due_at) : null;
@endphp

<div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1fr_20rem]">

    <div class="min-w-0">

        <a href="{{ route('tasks.index') }}" class="text-sm text-slate-500 hover:text-slate-900">
            ← همه‌ی تسک‌ها
        </a>

        <div class="mt-3 rounded-2xl border bg-white p-5 {{ $isOverdue ? 'border-red-200' : 'border-slate-200' }}">
            <h1 class="text-lg font-bold">{{ $task->title }}</h1>

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-slate-500">
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $task->status->label() }}</span>
                <span>{{ $task->assignee?->name ?? 'بدون مسئول' }}</span>

                @if ($due)
                    <span class="tabular {{ $isOverdue ? 'font-medium text-red-600' : '' }}">
                        {{ JalaliDate::format($due) }}
                        @if ($isOverdue)
                            — {{ $task->hoursOverdue() }} ساعت تأخیر
                        @endif
                    </span>
                @else
                    {{-- Said out loud rather than left blank: a task with no
                         deadline is never chased, and a manager who thinks
                         they set one is the failure this product exists to
                         prevent. --}}
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                        بدون ددلاین — پیگیری نمی‌شود
                    </span>
                @endif

                @if ($task->priority->isCritical())
                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">بحرانی</span>
                @endif

                @if ($task->defer_count > 0)
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                        {{ $task->defer_count }} بار تأخیر
                    </span>
                @endif
            </div>

            @if ($task->description)
                <p class="mt-3 whitespace-pre-line leading-8 text-slate-700">{{ $task->description }}</p>
            @endif

            @if ($task->defer_reason)
                <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    دلیل آخرین تأخیر: {{ $task->defer_reason }}
                </p>
            @endif

            {{-- Where it came from. Months later this is the answer to "این از
                 کجا آمد" that would otherwise need someone's memory. Only for
                 those who may read meetings: the title alone can say too much. --}}
            @if ($task->meeting && $canSeeMeetings)
                <p class="mt-3 text-sm">
                    <span class="text-slate-500">از جلسه:</span>
                    <a href="{{ route('meetings.show', $task->meeting) }}"
                       class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">
                        {{ $task->meeting->title }}
                    </a>
                </p>
            @endif

            @unless ($task->status->isClosed())
                <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    <form method="POST" action="{{ route('tasks.complete', $task) }}">
                        @csrf
                        <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            انجام شد
                        </button>
                    </form>

                    @if ($canCancel)
                        <form method="POST" action="{{ route('tasks.cancel', $task) }}"
                              data-confirm="این تسک لغو شود؟ پیگیری‌های باقی‌مانده هم حذف می‌شوند.">
                            @csrf
                            <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                لغو تسک
                            </button>
                        </form>
                    @endif
                </div>
            @endunless
        </div>

        {{-- The ladder, rung by rung. Skipped rungs stay visible with their
             reason: knowing why a message did not go out is worth as much as
             knowing that one did. --}}
        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">نردبان پیگیری</h2>

            @if ($task->followUps->isEmpty())
                <p class="mt-2 text-sm text-slate-500">
                    پیگیری‌ای زمان‌بندی نشده. تسک بدون ددلاین پیگیری نمی‌گیرد.
                </p>
            @else
                <ol class="mt-3 space-y-3">
                    @foreach ($task->followUps->sortBy('step') as $followUp)
                        @php
                            $tone = match ($followUp->status) {
                                FollowUpStatus::Sent => 'bg-emerald-500',
                                FollowUpStatus::Skipped => 'bg-slate-300',
                                FollowUpStatus::Failed => 'bg-red-500',
                                default => 'bg-amber-400',
                            };
                        @endphp

                        <li class="flex gap-3">
                            <span class="mt-2 size-2 shrink-0 rounded-full {{ $tone }}"></span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-sm">
                                    <span class="font-medium">{{ $followUp->step->label() }}</span>
                                    <span class="text-xs text-slate-400">
                                        {{ $followUp->recipient?->name ?? '—' }}
                                    </span>
                                </div>

                                <p class="tabular mt-0.5 text-xs text-slate-500">
                                    @switch ($followUp->status)
                                        @case (FollowUpStatus::Sent)
                                            فرستاده شد — {{ JalaliDate::format(CarbonImmutable::parse($followUp->sent_at)) }}
                                            @break
                                        @case (FollowUpStatus::Skipped)
                                            نرفت: {{ $followUp->skipExplanation() }}
                                            @break
                                        @case (FollowUpStatus::Failed)
                                            ناموفق: {{ $followUp->skipExplanation() ?? 'خطای سرویس' }}
                                            @break
                                        @default
                                            در انتظار — {{ $followUp->whenDue() }}
                                    @endswitch
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">تاریخچه</h2>

            @if ($activities->isEmpty())
                <p class="mt-2 text-sm text-slate-500">رویدادی ثبت نشده.</p>
            @else
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($activities as $activity)
                        <li class="flex flex-wrap items-baseline gap-x-2 border-b border-slate-50 pb-2 last:border-0">
                            <span class="text-slate-700">{{ $activity->label() }}</span>
                            <span class="text-xs text-slate-400">
                                {{-- No actor means the engine did it, which is
                                     most of what lands here. --}}
                                {{ $activity->user?->name ?? 'سامانه' }}
                            </span>
                            <span class="tabular ms-auto text-xs text-slate-400">
                                {{ JalaliDate::format(CarbonImmutable::parse($activity->created_at)) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <aside class="space-y-4">
        @unless ($task->status->isClosed())
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <h2 class="font-medium">جابه‌جایی ددلاین</h2>
                <p class="mt-1 text-xs text-slate-500">
                    نردبان پیگیری بر اساس تاریخ جدید بازسازی می‌شود.
                </p>

                <form method="POST" action="{{ route('tasks.reschedule', $task) }}" class="mt-3 space-y-3">
                    @csrf

                    <div>
                        <label for="due_date" class="block text-sm">تاریخ جدید</label>
                        <input id="due_date" name="due_date" required
                               value="{{ old('due_date', $due ? JalaliDate::format($due) : '') }}"
                               dir="ltr" placeholder="1405/07/15"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        @error('due_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="due_time" class="block text-sm">ساعت</label>
                        <input id="due_time" name="due_time" type="time"
                               value="{{ old('due_time', $due?->format('H:i')) }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        @error('due_time')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit"
                            class="w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                        ثبت ددلاین جدید
                    </button>
                </form>
            </div>
        @endunless

        <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
            <h2 class="font-medium">مشخصات</h2>

            <dl class="mt-2 space-y-1.5 text-slate-600">
                <div class="flex gap-2">
                    <dt class="text-slate-400">ثبت‌کننده</dt>
                    <dd class="ms-auto">{{ $task->creator?->name ?? '—' }}</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="text-slate-400">اولویت</dt>
                    <dd class="ms-auto">{{ $task->priority->label() }}</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="text-slate-400">ثبت شده در</dt>
                    <dd class="tabular ms-auto">
                        {{ JalaliDate::format(CarbonImmutable::parse($task->created_at)) }}
                    </dd>
                </div>
                @if ($task->completed_at)
                    <div class="flex gap-2">
                        <dt class="text-slate-400">بسته شده در</dt>
                        <dd class="tabular ms-auto">
                            {{ JalaliDate::format(CarbonImmutable::parse($task->completed_at)) }}
                        </dd>
                    </div>
                @endif
            </dl>
        </div>
    </aside>
</div>

@endsection
