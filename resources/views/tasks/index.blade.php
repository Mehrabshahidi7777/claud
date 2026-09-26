@extends('layouts.app')

@section('title', 'کارها')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="grid gap-6 lg:grid-cols-[1fr_22rem]">

    <div>
        {{-- Search and export sit above the filters: a manager looking for
             "that plumbing job" should not have to guess which tab it is on. --}}
        <form method="GET" action="{{ route('tasks.index') }}" class="mb-3 flex gap-2">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <input type="search" name="q" value="{{ $search }}" placeholder="جست‌وجو در عنوان کار یا نام مسئول"
                   class="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            <button class="shrink-0 rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">جست‌وجو</button>
            <a href="{{ route('tasks.export', array_filter(['filter' => $filter, 'q' => $search])) }}"
               class="shrink-0 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
               title="دانلود همین فهرست برای اکسل">
                خروجی اکسل
            </a>
        </form>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            @foreach ([
                'open' => 'باز',
                'overdue' => 'عقب‌افتاده',
                'mine' => 'مال من',
                'done' => 'بسته‌شده',
            ] as $key => $label)
                <a href="{{ route('tasks.index', array_filter(['filter' => $key, 'q' => $search])) }}"
                   class="rounded-lg px-3 py-1.5 text-sm {{ $filter === $key ? 'bg-brand-700 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        @if ($tasks->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-slate-600">
                    @if ($search !== '')
                        کاری با «{{ $search }}» در این فهرست پیدا نشد.
                    @else
                        کاری در این فهرست نیست.
                    @endif
                </p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($tasks as $task)
                    @php
                        $isOverdue = $task->isOverdue();
                        $due = $task->due_at ? CarbonImmutable::parse($task->due_at) : null;
                    @endphp

                    <div class="rounded-2xl border bg-white p-4 {{ $isOverdue ? 'border-red-200' : 'border-slate-200' }}">
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('tasks.show', $task) }}"
                                   class="font-medium hover:underline hover:decoration-slate-300 hover:underline-offset-4">
                                    {{ $task->title }}
                                </a>

                                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500">
                                    <span>{{ $task->assignee?->name ?? 'بدون مسئول' }}</span>

                                    @if ($due)
                                        <span class="tabular {{ $isOverdue ? 'font-medium text-red-600' : '' }}">
                                            {{ JalaliDate::format($due) }}
                                            @if ($isOverdue)
                                                — {{ $task->hoursOverdue() }} ساعت تأخیر
                                            @endif
                                        </span>
                                    @endif

                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                                        {{ $task->status->label() }}
                                    </span>

                                    @if ($task->priority->isCritical())
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">
                                            بحرانی
                                        </span>
                                    @endif

                                    @if ($task->defer_count > 0)
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                                            {{ $task->defer_count }} بار تأخیر
                                        </span>
                                    @endif
                                </div>

                                {{-- What the engine will do next, in plain words. This is the
                                     line that makes the product legible in a demo: the manager
                                     can see that something is going to happen without them. --}}
                                @php
                                    $next = $task->followUps
                                        ->where('status', App\Enums\FollowUpStatus::Pending)
                                        ->sortBy('scheduled_at')
                                        ->first();
                                @endphp

                                @if ($next && ! $task->status->isClosed())
                                    <p class="mt-2 text-xs text-slate-400">
                                        بعدی: {{ $next->step->label() }} —
                                        <span class="tabular">{{ $next->whenDue() }}</span>
                                    </p>
                                @endif
                            </div>

                            @unless ($task->status->isClosed())
                                <div class="flex shrink-0 gap-2">
                                    <form method="POST" action="{{ route('tasks.complete', $task) }}">
                                        @csrf
                                        <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white hover:bg-emerald-700">
                                            انجام شد
                                        </button>
                                    </form>
                                </div>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $tasks->links() }}</div>
        @endif
    </div>

    <aside class="space-y-4">

        {{-- The AI shortcut. It sits above the manual form and degrades to it:
             a paragraph goes in, drafts come back, the manager confirms. --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4" x-data>
            <h2 class="font-medium">ثبت سریع با متن آزاد</h2>
            <p class="mt-1 text-xs text-slate-500">
                هرچه در ذهن دارید بنویسید. کارها از دل متن درمی‌آیند و خودتان تأییدشان می‌کنید.
            </p>

            <textarea id="ai-text" rows="4"
                      placeholder="فردا ساعت ۳ با آقای رضایی جلسه، گزارش را هم تا پنجشنبه آماده کن"
                      class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"></textarea>

            <button id="ai-parse" type="button"
                    class="mt-2 w-full rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                تبدیل به کار
            </button>

            <p id="ai-message" class="mt-2 hidden text-xs"></p>
            <div id="ai-drafts" class="mt-3 hidden space-y-2"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">کار جدید</h2>

            <form method="POST" action="{{ route('tasks.store') }}" class="mt-3 space-y-3">
                @csrf

                <div>
                    <label for="title" class="block text-sm">عنوان</label>
                    <input id="title" name="title" value="{{ old('title') }}" required
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="description" class="block text-sm">توضیح <span class="text-xs text-slate-400">(اختیاری)</span></label>
                    <textarea id="description" name="description" rows="2"
                              placeholder="جزئیاتی که مجری لازم دارد بداند"
                              class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">{{ old('description') }}</textarea>
                    @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="assignee_id" class="block text-sm">مسئول</label>
                    <select id="assignee_id" name="assignee_id"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        <option value="">— بدون مسئول —</option>
                        @foreach ($members as $member)
                            <option value="{{ $member->id }}" @selected(old('assignee_id') == $member->id)>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="due_date" class="block text-sm">مهلت انجام</label>
                        <input id="due_date" name="due_date" value="{{ old('due_date') }}"
                               dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()->addDays(7)) }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @error('due_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="due_time" class="block text-sm">ساعت</label>
                        <input id="due_time" name="due_time" type="time" value="{{ old('due_time') }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    </div>
                </div>

                <div>
                    <label for="priority" class="block text-sm">اولویت</label>
                    <select id="priority" name="priority"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @foreach (App\Enums\TaskPriority::cases() as $priority)
                            <option value="{{ $priority->value }}"
                                    @selected(old('priority', 'normal') === $priority->value)>
                                {{ $priority->label() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">
                        اولویت «کم» پیامک نمی‌گیرد؛ پیگیری‌اش فقط درون‌برنامه‌ای است.
                    </p>
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="may_break_quiet_hours" value="1" class="mt-1"
                           @checked(old('may_break_quiet_hours'))>
                    <span class="text-slate-600">
                        پیامک حتی در ساعت استراحت برود
                        <span class="block text-xs text-slate-400">
                            از ۹ شب تا ۸ صبح معمولاً پیامکی نمی‌رود. این گزینه فقط برای کار بحرانی است.
                        </span>
                    </span>
                </label>

                <button type="submit"
                        class="w-full rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                    ثبت کار
                </button>
            </form>
        </div>

        @if ($showsSmsAllowance)
            @php
                $remaining = $workspace->remainingSmsCredit();
                $isRunningLow = $workspace->sms_quota > 0 && $remaining <= $workspace->sms_quota * 0.1;
            @endphp

            <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
                <h2 class="font-medium text-slate-900">پیامک‌های پیگیری این ماه</h2>
                <p class="tabular mt-2">
                    {{ number_format($remaining) }} از {{ number_format($workspace->sms_quota) }} مانده
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    هر پلن سهمیه‌ی ماهانه‌ی پیامک دارد و هر ماه دوباره پر می‌شود.
                </p>

                @if (! $workspace->sms_enabled)
                    <p class="mt-2 rounded-lg bg-amber-50 px-2 py-1 text-xs text-amber-800">
                        ارسال پیامک برای این فضای کاری خاموش است.
                    </p>
                @elseif ($isRunningLow)
                    <p class="mt-2 rounded-lg bg-amber-50 px-2 py-1 text-xs text-amber-800">
                        سهمیه‌ی این ماه رو به اتمام است. وقتی تمام شود، پیامک‌های پیگیری تا ماه بعد نمی‌روند.
                    </p>
                @endif
            </div>
        @endif
    </aside>
</div>

@endsection
