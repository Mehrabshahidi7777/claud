@extends('layouts.app')

@section('title', $contract->title)

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $expiredNow = $contract->hasExpired();
    $days = $contract->daysToExpiry();
@endphp

<div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1fr_21rem]">

    <div class="min-w-0">
        <a href="{{ route('contracts.index') }}" class="text-sm text-slate-500 hover:text-slate-900">
            ← همه‌ی قراردادها
        </a>

        <div class="mt-3 rounded-2xl border bg-white p-5 {{ $expiredNow ? 'border-red-200' : 'border-slate-200' }}">
            <h1 class="text-lg font-bold">{{ $contract->title }}</h1>

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-slate-500">
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $contract->kind->label() }}</span>
                <span>{{ $contract->party_type->label() }}: {{ $contract->party_name }}</span>

                @unless ($contract->isActive())
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $contract->status->label() }}</span>
                @endunless
            </div>

            <div class="tabular mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                <span class="text-slate-500">
                    {{ JalaliDate::format(CarbonImmutable::parse($contract->starts_on)) }}
                    تا
                    <span class="{{ $expiredNow ? 'font-medium text-red-600' : '' }}">
                        {{ JalaliDate::format(CarbonImmutable::parse($contract->expires_on)) }}
                    </span>
                </span>

                @if ($contract->isActive())
                    @if ($expiredNow)
                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">
                            {{ abs($days) }} روز از انقضا گذشته
                        </span>
                    @else
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $days }} روز مانده</span>
                    @endif
                @endif

                @if ($contract->value)
                    <span class="ms-auto font-medium">{{ number_format($contract->value) }}
                        <span class="text-xs font-normal text-slate-400">ریال</span>
                    </span>
                @endif
            </div>

            @if ($contract->auto_renews)
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    این قرارداد خودبه‌خود تمدید می‌شود. اگر نمی‌خواهید تمدید شود،
                    باید قبل از تاریخ انقضا اقدام کنید.
                </p>
            @endif

            @if ($contract->note)
                <p class="mt-3 whitespace-pre-line leading-8 text-slate-700">{{ $contract->note }}</p>
            @endif

            @if ($contract->isActive())
                <form method="POST" action="{{ route('contracts.end', $contract) }}"
                      class="mt-4 border-t border-slate-100 pt-4"
                      data-confirm="این قرارداد خاتمه‌یافته ثبت شود؟ سابقه‌اش باقی می‌ماند.">
                    @csrf
                    <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                        خاتمه‌یافته ثبت شود
                    </button>
                </form>
            @endif
        </div>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">دوره‌های قبلی</h2>

            @if ($contract->terms->isEmpty())
                <p class="mt-2 text-sm text-slate-500">
                    هنوز تمدید نشده. دوره‌ی جاری اولین دوره است.
                </p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($contract->terms as $term)
                        <li class="tabular flex flex-wrap items-baseline gap-2 py-2">
                            <span>
                                {{ JalaliDate::format(CarbonImmutable::parse($term->starts_on)) }}
                                تا
                                {{ JalaliDate::format(CarbonImmutable::parse($term->expires_on)) }}
                            </span>

                            @if ($term->value)
                                <span class="text-slate-500">{{ number_format($term->value) }}</span>
                            @endif

                            @if ($term->note)
                                <span class="w-full text-xs text-slate-500">{{ $term->note }}</span>
                            @endif

                            <span class="ms-auto text-xs text-slate-400">{{ $term->recorder?->name }}</span>
                        </li>
                    @endforeach
                </ul>
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
                            <span class="text-xs text-slate-400">{{ $activity->user?->name ?? 'سامانه' }}</span>
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
        @if ($contract->isActive())
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <h2 class="font-medium">ثبت تمدید</h2>
                <p class="mt-1 text-xs text-slate-500">
                    دوره‌ی فعلی به تاریخچه می‌رود و دوره‌ی جدید جایش می‌نشیند.
                    طول دوره هرچه توافق شده — لازم نیست مثل قبلی باشد.
                </p>

                <form method="POST" action="{{ route('contracts.renew', $contract) }}" class="mt-3 space-y-3">
                    @csrf

                    <div>
                        <label for="starts_date" class="block text-sm">شروع دوره‌ی جدید</label>
                        <input id="starts_date" name="starts_date" required dir="ltr"
                               value="{{ old('starts_date', JalaliDate::format(CarbonImmutable::parse($contract->expires_on)->addDay())) }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        @error('starts_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="expires_date" class="block text-sm">انقضای جدید</label>
                        <input id="expires_date" name="expires_date" required dir="ltr" placeholder="1406/12/29"
                               value="{{ old('expires_date') }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        @error('expires_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="value" class="block text-sm">مبلغ جدید <span class="text-xs text-slate-400">(اختیاری)</span></label>
                        <input id="value" name="value" inputmode="numeric" dir="ltr" value="{{ old('value') }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    </div>

                    <div>
                        <label for="note" class="block text-sm">توضیح دوره‌ی قبل</label>
                        <input id="note" name="note" value="{{ old('note') }}"
                               class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    </div>

                    <button type="submit"
                            class="w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                        ثبت تمدید
                    </button>
                </form>
            </div>
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
            <h2 class="font-medium">مشخصات</h2>

            <dl class="mt-2 space-y-1.5 text-slate-600">
                <div class="flex gap-2">
                    <dt class="text-slate-400">مسئول تمدید</dt>
                    <dd class="ms-auto">{{ $contract->owner?->name ?? '—' }}</dd>
                </div>
                @if ($contract->reference)
                    <div class="flex gap-2">
                        <dt class="text-slate-400">شماره</dt>
                        <dd class="tabular ms-auto">{{ $contract->reference }}</dd>
                    </div>
                @endif
                <div class="flex gap-2">
                    <dt class="text-slate-400">یادآوری</dt>
                    <dd class="tabular ms-auto">{{ $contract->notice_days }} روز قبل</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="text-slate-400">تعداد تمدید</dt>
                    <dd class="tabular ms-auto">{{ $contract->renewals }}</dd>
                </div>
                @if ($contract->ended_on)
                    <div class="flex gap-2">
                        <dt class="text-slate-400">خاتمه</dt>
                        <dd class="tabular ms-auto">
                            {{ JalaliDate::format(CarbonImmutable::parse($contract->ended_on)) }}
                        </dd>
                    </div>
                @endif
            </dl>
        </div>

        @if ($contract->tasks->isNotEmpty())
            <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
                <h2 class="font-medium">تسک‌های تمدید</h2>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($contract->tasks as $task)
                        <li class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('tasks.show', $task) }}" class="min-w-0 flex-1 truncate hover:underline">
                                {{ $task->title }}
                            </a>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                                {{ $task->status->label() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </aside>
</div>

@endsection
