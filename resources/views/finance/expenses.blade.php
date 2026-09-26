@extends('layouts.app')

@section('title', 'هزینه‌ها')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1fr_22rem]">

    <div class="min-w-0">
        <div class="mb-4 flex flex-wrap items-baseline gap-3">
            <h1 class="text-lg font-bold">هزینه‌ها</h1>
            <a href="{{ route('finance.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← گزارش مالی</a>
        </div>

        @if ($expenses->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-slate-600">هنوز هزینه‌ای ثبت نشده.</p>
                <p class="mt-2 text-xs text-slate-400">
                    یک جمله بنویسید و بگذارید خودش مبلغ و تاریخ را دربیاورد.
                </p>
            </div>
        @else
            <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white">
                @foreach ($expenses as $expense)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 p-4">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $expense->title }}</p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                <span class="rounded-full bg-slate-100 px-2 py-0.5">{{ $expense->category->label() }}</span>
                                @if ($expense->vendor)<span>{{ $expense->vendor }}</span>@endif
                                <span class="tabular">{{ JalaliDate::format(CarbonImmutable::parse($expense->spent_on)) }}</span>

                                @if ($expense->approvalRequest)
                                    <span class="text-emerald-700">تأییدشده</span>
                                @endif
                            </p>
                        </div>

                        <span class="tabular font-medium">{{ number_format($expense->amount) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $expenses->links() }}</div>
        @endif
    </div>

    <aside class="space-y-4">

        {{-- Same shape as the task shortcut: a sentence in, a filled form
             back, the manager confirms. Nothing is stored by the model. --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">ثبت سریع با متن آزاد</h2>
            <p class="mt-1 text-xs text-slate-500">
                «بابت خرید دستگاه جوش از فروشگاه سام ۱۸۵ میلیون ریال، دوازدهم مهر»
            </p>

            <form method="POST" action="{{ route('finance.expenses.parse') }}" class="mt-2">
                @csrf
                <textarea name="text" rows="3" required
                          class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">{{ old('text') }}</textarea>

                @error('ai')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                @error('text')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

                <button class="mt-2 w-full rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                    پر کردن فرم از روی متن
                </button>
            </form>
        </div>

        <div class="rounded-2xl border {{ $draft ? 'border-emerald-300' : 'border-slate-200' }} bg-white p-4">
            <h2 class="font-medium">{{ $draft ? 'تأیید هزینه' : 'ثبت هزینه' }}</h2>

            @if ($draft)
                <p class="mt-1 text-xs text-emerald-700">
                    از متن شما درآمد. قبل از ثبت، مبلغ را یک بار چک کنید.
                </p>
            @endif

            <form method="POST" action="{{ route('finance.expenses.store') }}" class="mt-3 space-y-3">
                @csrf

                <div>
                    <label for="title" class="block text-sm">شرح</label>
                    <input id="title" name="title" required
                           value="{{ old('title', $draft['title'] ?? '') }}"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="amount" class="block text-sm">مبلغ <span class="text-xs text-slate-400">(ریال)</span></label>
                    <input id="amount" name="amount" required inputmode="numeric" dir="ltr"
                           value="{{ old('amount', $draft['amount'] ?? '') }}"
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="category" class="block text-sm">دسته</label>
                    <select id="category" name="category"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @foreach ($categories as $category)
                            <option value="{{ $category->value }}"
                                    @selected(old('category', $draft['category'] ?? 'other') === $category->value)>
                                {{ $category->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="vendor" class="block text-sm">طرف حساب <span class="text-xs text-slate-400">(اختیاری)</span></label>
                    <input id="vendor" name="vendor"
                           value="{{ old('vendor', $draft['vendor'] ?? '') }}"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                </div>

                <div>
                    <label for="spent_date" class="block text-sm">تاریخ</label>
                    <input id="spent_date" name="spent_date" required dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()) }}"
                           value="{{ old('spent_date', $draft['spent_date'] ?? JalaliDate::format(CarbonImmutable::now())) }}"
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('spent_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                @if ($openApprovals->isNotEmpty())
                    <div>
                        <label for="approval_request_id" class="block text-sm">
                            بابت کدام تأییدیه؟ <span class="text-xs text-slate-400">(اختیاری)</span>
                        </label>
                        <select id="approval_request_id" name="approval_request_id"
                                class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                            <option value="">— بدون تأییدیه —</option>
                            @foreach ($openApprovals as $approval)
                                <option value="{{ $approval->id }}" @selected(old('approval_request_id') == $approval->id)>
                                    {{ $approval->title }} — {{ number_format($approval->amount) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <button type="submit"
                        class="w-full rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                    ثبت هزینه
                </button>
            </form>
        </div>
    </aside>
</div>

@endsection
