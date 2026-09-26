@extends('layouts.app')

@section('title', 'ثبت جلسه')

@section('content')

<div class="mx-auto max-w-2xl">
    <h1 class="mb-1 text-lg font-bold">ثبت صورتجلسه</h1>
    <p class="mb-4 text-sm text-slate-500">
        متن جلسه را همان‌طور که هست بچسبانید. کارهای جلسه از دل متن درمی‌آیند و خودتان
        تأییدشان می‌کنید.
    </p>

    <form method="POST" action="{{ route('meetings.store') }}"
          class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
        @csrf

        <div>
            <label for="title" class="block text-sm font-medium">عنوان جلسه</label>
            <input id="title" name="title" value="{{ old('title') }}" required autofocus
                   placeholder="جلسه هفتگی عملیات"
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="held_date" class="block text-sm font-medium">تاریخ برگزاری</label>
            <input id="held_date" name="held_date" value="{{ old('held_date') }}"
                   dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()) }}"
                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            <p class="mt-1 text-xs text-slate-400">خالی بگذارید تا امروز ثبت شود.</p>
            @error('held_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium">متن جلسه</label>
            <textarea id="notes" name="notes" rows="12" required
                      placeholder="رضا تا پنجشنبه گزارش سرویس‌ها را آماده کند. درباره قیمت پروژه جردن بحث شد و قرار شد حسین فردا با کارفرما تماس بگیرد…"
                      class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm leading-7 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">{{ old('notes') }}</textarea>
            @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        {{-- Said plainly rather than promised. The notes are kept either way,
             which is most of the value on its own. --}}
        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
            فقط چیزی «اقدام» می‌شود که کسی صریحاً قبولش کرده باشد. بحث بدون تعهد
            اقدام نیست. هیچ کاری بدون تأیید شما ساخته نمی‌شود.
        </p>

        <button type="submit"
                class="w-full rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-800">
            ثبت و پیدا کردن کارها
        </button>
    </form>
</div>

@endsection
