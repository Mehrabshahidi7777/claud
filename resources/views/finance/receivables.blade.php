@extends('layouts.app')

@section('title', 'مطالبات')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1fr_22rem]">

    <div class="min-w-0">
        <div class="mb-4 flex flex-wrap items-baseline gap-3">
            <h1 class="text-lg font-bold">مطالبات</h1>
            <a href="{{ route('finance.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← گزارش مالی</a>
            <a href="{{ route('finance.import') }}"
               class="ms-auto rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                درون‌ریزی از حسابداری
            </a>
        </div>

        @if ($receivables->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-slate-600">مطالبه‌ای ثبت نشده.</p>
                <p class="mt-2 text-xs text-slate-400">
                    هر مطالبه‌ای که سررسیدش بگذرد، خودش تبدیل به تسک می‌شود و
                    مسئولش پیگیری می‌شود.
                </p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($receivables as $receivable)
                    <div class="rounded-2xl border bg-white p-4 {{ $receivable->isOverdue() ? 'border-amber-300' : 'border-slate-200' }}">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-medium">{{ $receivable->customer_name }}</span>
                            <span class="text-sm text-slate-500">{{ $receivable->title }}</span>

                            <span class="rounded-full px-2 py-0.5 text-xs {{ $receivable->status->badgeClasses() }}">
                                {{ $receivable->status->label() }}
                            </span>

                            <span class="tabular ms-auto font-medium">
                                {{ number_format($receivable->outstanding()) }}
                                <span class="text-xs font-normal text-slate-400">ریال</span>
                            </span>
                        </div>

                        <div class="tabular mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                            <span>سررسید {{ JalaliDate::format(CarbonImmutable::parse($receivable->due_on)) }}</span>

                            @if ($receivable->isOverdue())
                                <span class="font-medium text-amber-700">{{ $receivable->daysOverdue() }} روز تأخیر</span>
                            @endif

                            <span>مسئول: {{ $receivable->owner?->name ?? 'مشخص نشده' }}</span>

                            @if ($receivable->settled_amount > 0 && $receivable->status->isOutstanding())
                                <span>{{ number_format($receivable->settled_amount) }} دریافت شده</span>
                            @endif

                            @if ($receivable->task)
                                <a href="{{ route('tasks.show', $receivable->task) }}"
                                   class="text-emerald-700 hover:underline">تسک پیگیری</a>
                            @endif
                        </div>

                        @if ($receivable->status->isOutstanding())
                            <form method="POST" action="{{ route('finance.receivables.settle', $receivable) }}"
                                  class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                                @csrf

                                <label for="received-{{ $receivable->id }}" class="text-xs text-slate-500">
                                    دریافت شد:
                                </label>
                                <input id="received-{{ $receivable->id }}" name="received" required
                                       inputmode="numeric" dir="ltr" max="{{ $receivable->outstanding() }}"
                                       placeholder="{{ $receivable->outstanding() }}"
                                       class="tabular w-40 rounded-lg border border-slate-300 px-2 py-1 text-xs focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">

                                <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs text-white hover:bg-emerald-700">
                                    ثبت دریافت
                                </button>

                                {{-- Said plainly, because it is the rule people
                                     get wrong: a part payment keeps being
                                     chased for the rest. --}}
                                <span class="text-xs text-slate-400">
                                    دریافت جزئی هم قابل ثبت است؛ باقی‌مانده پیگیری می‌شود.
                                </span>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $receivables->links() }}</div>
        @endif
    </div>

    <aside>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">ثبت مطالبه</h2>
            <p class="mt-1 text-xs text-slate-500">
                {{ config('finance.chase_after_days') }} روز بعد از سررسید، خودش
                تبدیل به تسک می‌شود و مسئولش پیگیری می‌شود.
            </p>

            <form method="POST" action="{{ route('finance.receivables.store') }}" class="mt-3 space-y-3">
                @csrf

                <div>
                    <label for="customer_name" class="block text-sm">نام مشتری</label>
                    <input id="customer_name" name="customer_name" required value="{{ old('customer_name') }}"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('customer_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="title" class="block text-sm">بابت</label>
                    <input id="title" name="title" required value="{{ old('title') }}"
                           placeholder="صورت‌وضعیت شماره ۳"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="amount" class="block text-sm">مبلغ <span class="text-xs text-slate-400">(ریال)</span></label>
                    <input id="amount" name="amount" required inputmode="numeric" dir="ltr" value="{{ old('amount') }}"
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="issued_date" class="block text-sm">تاریخ صدور</label>
                        <input id="issued_date" name="issued_date" required dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()) }}"
                               value="{{ old('issued_date', JalaliDate::format(CarbonImmutable::now())) }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @error('issued_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="due_date" class="block text-sm">سررسید</label>
                        <input id="due_date" name="due_date" required dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()->addDays(30)) }}"
                               value="{{ old('due_date') }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @error('due_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div>
                    <label for="owner_id" class="block text-sm">مسئول وصول</label>
                    <select id="owner_id" name="owner_id"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        <option value="">— مالک فضای کاری —</option>
                        @foreach ($members as $member)
                            <option value="{{ $member->id }}" @selected(old('owner_id') == $member->id)>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">
                        پیگیری سراغ این نفر می‌رود، نه سراغ مشتری.
                    </p>
                </div>

                <button type="submit"
                        class="w-full rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                    ثبت مطالبه
                </button>
            </form>
        </div>
    </aside>
</div>

@endsection
