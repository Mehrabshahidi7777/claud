@extends('layouts.app')

@section('title', 'کارهای دوره‌ای')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-[1fr_23rem]">

    <div class="min-w-0">
        <h1 class="mb-1 text-lg font-bold">کارهای دوره‌ای</h1>
        <p class="mb-4 text-sm text-slate-500">
            هر چیزی که تکرار می‌شود — سرویس مشتری، بیمه، قبض، تعویض روغن. سر
            وقتش خودش تبدیل به تسک می‌شود و پیگیری می‌شود.
        </p>

        {{-- The money line. Shown only where the work is priced: on a family
             plan nobody puts a number on changing the car's oil, and an empty
             "۰ ریال" tile would be worse than no tile at all. --}}
        @if ($valueAtRisk > 0 || $earnedThisYear > 0)
            <div class="mb-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl border bg-white p-4 {{ $valueAtRisk > 0 ? 'border-red-200' : 'border-slate-200' }}">
                    <p class="text-sm text-slate-500">درآمد در معرض از دست رفتن</p>
                    <p class="tabular mt-1 text-2xl font-bold {{ $valueAtRisk > 0 ? 'text-red-700' : '' }}">
                        {{ number_format($valueAtRisk) }}
                        <span class="text-sm font-normal text-slate-400">ریال</span>
                    </p>
                    <p class="tabular mt-1 text-xs text-slate-500">
                        {{ $overdueCount }} سرویس از تاریخش گذشته
                    </p>
                </div>

                <div class="rounded-2xl border border-emerald-200 bg-white p-4">
                    <p class="text-sm text-slate-500">درآمد دوره‌ای یک سال اخیر</p>
                    <p class="tabular mt-1 text-2xl font-bold text-emerald-700">
                        {{ number_format($earnedThisYear) }}
                        <span class="text-sm font-normal text-slate-400">ریال</span>
                    </p>
                    <p class="mt-1 text-xs text-slate-500">از سرویس‌هایی که انجام شده‌اند</p>
                </div>
            </div>
        @endif

        @if ($recurrences->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-slate-600">هنوز کار دوره‌ای ثبت نشده.</p>
                <p class="mt-2 text-xs text-slate-400">
                    سرویس شش‌ماهه‌ی مشتری، بیمه‌ی ماشین، قبض فصلی — هرکدام یک ردیف.
                </p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($recurrences as $recurrence)
                    @php
                        $overdue = $recurrence->isOverdue();
                        $dueNow = ! $overdue && $recurrence->isDueToRaise();

                        $border = ! $recurrence->is_active
                            ? 'border-slate-200 opacity-60'
                            : ($overdue ? 'border-red-200' : ($dueNow ? 'border-amber-200' : 'border-slate-200'));
                    @endphp

                    <div class="rounded-2xl border bg-white p-4 {{ $border }}">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-medium">{{ $recurrence->title }}</span>

                            @if ($recurrence->isServiceContract())
                                <span class="text-sm text-slate-500">{{ $recurrence->customer_name }}</span>
                            @endif

                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                                {{ $recurrence->intervalLabel() }}
                            </span>

                            @unless ($recurrence->is_active)
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">متوقف</span>
                            @endunless

                            @if ($recurrence->estimated_value)
                                <span class="tabular ms-auto text-sm font-medium">
                                    {{ number_format($recurrence->estimated_value) }}
                                </span>
                            @endif
                        </div>

                        <div class="tabular mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                            <span class="{{ $overdue ? 'font-medium text-red-600' : '' }}">
                                نوبت بعدی: {{ JalaliDate::format(CarbonImmutable::parse($recurrence->next_due_on)) }}
                            </span>

                            @if ($overdue)
                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-red-700">گذشته</span>
                            @elseif ($dueNow)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">تسکش ساخته شده</span>
                            @endif

                            <span>{{ $recurrence->assignee?->name ?? 'بدون مسئول' }}</span>

                            @if ($recurrence->occurrences > 0)
                                <span>{{ $recurrence->occurrences }} بار انجام شده</span>
                            @endif

                            @if ($recurrence->last_done_on)
                                <span>آخرین بار {{ JalaliDate::format(CarbonImmutable::parse($recurrence->last_done_on)) }}</span>
                            @endif

                            <form method="POST" action="{{ route('recurring.toggle', $recurrence) }}" class="ms-auto">
                                @csrf
                                <button class="text-xs text-slate-400 hover:text-slate-900">
                                    {{ $recurrence->is_active ? 'توقف' : 'فعال‌سازی' }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <aside>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">کار دوره‌ای جدید</h2>

            <form method="POST" action="{{ route('recurring.store') }}" class="mt-3 space-y-3">
                @csrf

                <div>
                    <label for="title" class="block text-sm">عنوان</label>
                    <input id="title" name="title" required value="{{ old('title') }}"
                           placeholder="سرویس دوره‌ای چیلر"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid grid-cols-[5rem_1fr] gap-2">
                    <div>
                        <label for="interval_count" class="block text-sm">هر</label>
                        <input id="interval_count" name="interval_count" required inputmode="numeric" dir="ltr"
                               value="{{ old('interval_count', 6) }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    </div>
                    <div>
                        <label for="interval_unit" class="block text-sm">واحد</label>
                        <select id="interval_unit" name="interval_unit"
                                class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                            @foreach ($units as $unit)
                                <option value="{{ $unit->value }}" @selected(old('interval_unit', 'month') === $unit->value)>
                                    {{ $unit->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @error('interval_count')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                <div>
                    <label for="next_due_date" class="block text-sm">نوبت بعدی</label>
                    <input id="next_due_date" name="next_due_date" required dir="ltr" placeholder="1405/08/15"
                           value="{{ old('next_due_date') }}"
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    @error('next_due_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="lead_days" class="block text-sm">چند روز قبلش یادآوری شود</label>
                    <input id="lead_days" name="lead_days" required inputmode="numeric" dir="ltr"
                           value="{{ old('lead_days', 7) }}"
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    <p class="mt-1 text-xs text-slate-400">
                        سرویسی که همان روزِ موعد ظاهر شود فروخته نمی‌شود — باید وقت
                        هماهنگی با مشتری باشد.
                    </p>
                    @error('lead_days')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="anchor" class="block text-sm">دوره‌ی بعدی از کِی شمرده شود</label>
                    <select id="anchor" name="anchor" data-anchor
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        @foreach ($anchors as $anchor)
                            <option value="{{ $anchor->value }}" data-hint="{{ $anchor->hint() }}"
                                    @selected(old('anchor', 'scheduled') === $anchor->value)>
                                {{ $anchor->label() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400" data-anchor-hint>{{ $anchors[0]->hint() }}</p>
                </div>

                <details class="rounded-xl border border-slate-200 p-3">
                    <summary class="cursor-pointer text-sm text-slate-700">
                        مشتری و مبلغ <span class="text-xs text-slate-400">(برای قرارداد سرویس)</span>
                    </summary>

                    <div class="mt-3 space-y-3">
                        <div>
                            <label for="customer_name" class="block text-sm">نام مشتری</label>
                            <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}"
                                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        </div>

                        <div>
                            <label for="customer_phone" class="block text-sm">شماره تماس مشتری</label>
                            <input id="customer_phone" name="customer_phone" dir="ltr" value="{{ old('customer_phone') }}"
                                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        </div>

                        <div>
                            <label for="estimated_value" class="block text-sm">
                                ارزش هر نوبت <span class="text-xs text-slate-400">(ریال)</span>
                            </label>
                            <input id="estimated_value" name="estimated_value" inputmode="numeric" dir="ltr"
                                   value="{{ old('estimated_value') }}"
                                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                            <p class="mt-1 text-xs text-slate-400">
                                همین عدد است که می‌گوید فراموش کردن سرویس‌ها چقدر برایتان آب می‌خورد.
                            </p>
                        </div>
                    </div>
                </details>

                <div>
                    <label for="assignee_id" class="block text-sm">مسئول</label>
                    <select id="assignee_id" name="assignee_id"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        <option value="">— خودم —</option>
                        @foreach ($members as $member)
                            <option value="{{ $member->id }}" @selected(old('assignee_id') == $member->id)>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="priority" class="block text-sm">اولویت</label>
                    <select id="priority" name="priority"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->value }}" @selected(old('priority', 'normal') === $priority->value)>
                                {{ $priority->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <button type="submit"
                        class="w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    ثبت کار دوره‌ای
                </button>
            </form>
        </div>
    </aside>
</div>

@endsection
