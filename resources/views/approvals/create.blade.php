@extends('layouts.app')

@section('title', 'درخواست جدید')

@section('content')

<div class="mx-auto max-w-2xl">
    <h1 class="mb-1 text-lg font-bold">درخواست جدید</h1>
    <p class="mb-4 text-sm text-slate-500">
        مرخصی، خرید یا هزینه. به مدیرتان اطلاع داده می‌شود و تصمیمش اینجا ثبت
        می‌ماند.
    </p>

    <form method="POST" action="{{ route('approvals.store') }}" data-approval-form
          class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
        @csrf

        <div>
            <label for="type" class="block text-sm font-medium">نوع درخواست</label>
            <select id="type" name="type" data-approval-type
                    class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(old('type') === $type->value)>
                        {{ $type->label() }}
                    </option>
                @endforeach
            </select>
            @error('type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="title" class="block text-sm font-medium">عنوان</label>
            <input id="title" name="title" value="{{ old('title') }}" required
                   placeholder="مرخصی استحقاقی"
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        {{-- Shown for leave, hidden for the rest. The server decides what it
             stores either way — this is convenience, not validation. --}}
        <div data-approval-dates class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="starts_on" class="block text-sm font-medium">از تاریخ</label>
                <input id="starts_on" name="starts_on" value="{{ old('starts_on') }}"
                       dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()->addDays(7)) }}"
                       class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                @error('starts_on')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="ends_on" class="block text-sm font-medium">تا تاریخ</label>
                <input id="ends_on" name="ends_on" value="{{ old('ends_on') }}"
                       dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()->addDays(9)) }}"
                       class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                @error('ends_on')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div data-approval-amount class="hidden">
            <label for="amount" class="block text-sm font-medium">مبلغ (ریال)</label>
            <input id="amount" name="amount" value="{{ old('amount') }}" inputmode="numeric"
                   dir="ltr" placeholder="12000000"
                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            @error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="reason" class="block text-sm font-medium">توضیح</label>
            <textarea id="reason" name="reason" rows="4"
                      placeholder="دلیل درخواست…"
                      class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm leading-7 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">{{ old('reason') }}</textarea>
            @error('reason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
            مرخصی که تأیید شود، سامانه تا پایان آن بازه سراغ شما نمی‌آید —
            نه پیامکی، نه یادآوری‌ای.
        </p>

        <button type="submit"
                class="w-full rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-800">
            ثبت درخواست
        </button>
    </form>
</div>

@endsection
