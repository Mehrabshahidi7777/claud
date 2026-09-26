@extends('layouts.app')

@section('title', 'معرفی به دوستان')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $shareText = "من کارهایم را با ".config('brand.name')." پیگیری می‌کنم. با این لینک ".($trialDays + $bonusDays)." روز رایگان امتحانش کن:";
@endphp

<div class="mx-auto max-w-2xl">

    <h1 class="text-lg font-bold">معرفی به دوستان</h1>
    <p class="mt-1 text-sm text-slate-600">
        لینک زیر را برای آشنایانتان بفرستید؛ هر دو طرف هدیه می‌گیرید.
    </p>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs text-slate-500">دوستتان</p>
            <p class="mt-1 font-medium">{{ $trialDays + $bonusDays }} روز رایگان به‌جای {{ $trialDays }} روز</p>
            <p class="mt-1 text-xs text-slate-500">همان لحظه‌ی ثبت‌نام با لینک شما.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs text-slate-500">شما</p>
            <p class="mt-1 font-medium">{{ $bonusDays }} روز اضافه روی اشتراکتان</p>
            <p class="mt-1 text-xs text-slate-500">وقتی دوستتان اولین اشتراکش را خرید.</p>
        </div>
    </div>

    {{-- Copy and share are wired in app.js; without JavaScript the link is
         still there to select by hand. --}}
    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4" data-referral>
        <label for="referral-link" class="block text-sm font-medium">لینک اختصاصی شما</label>
        <div class="mt-2 flex gap-2">
            <input id="referral-link" value="{{ $link }}" readonly dir="ltr" data-referral-link
                   class="tabular min-w-0 flex-1 rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm">
            <button type="button" data-referral-copy
                    class="shrink-0 rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                کپی
            </button>
        </div>

        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            <a href="https://wa.me/?text={{ rawurlencode($shareText.' '.$link) }}" target="_blank" rel="noopener"
               class="rounded-xl border border-slate-300 px-3 py-1.5 hover:bg-slate-50">واتساپ</a>
            <a href="https://t.me/share/url?url={{ rawurlencode($link) }}&text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener"
               class="rounded-xl border border-slate-300 px-3 py-1.5 hover:bg-slate-50">تلگرام</a>
            <a href="sms:?body={{ rawurlencode($shareText.' '.$link) }}"
               class="rounded-xl border border-slate-300 px-3 py-1.5 hover:bg-slate-50">پیامک</a>
            <button type="button" data-referral-share data-share-text="{{ $shareText }}"
                    class="hidden rounded-xl border border-slate-300 px-3 py-1.5 hover:bg-slate-50">
                ارسال با…
            </button>
        </div>
    </div>

    <section class="mt-6">
        <h2 class="font-medium">کسانی که با لینک شما آمده‌اند</h2>

        @if ($referred->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500">
                هنوز کسی با لینک شما ثبت‌نام نکرده.
            </div>
        @else
            <ul class="mt-3 divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white">
                @foreach ($referred as $newcomer)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
                        <span class="font-medium">{{ $newcomer->name }}</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $newcomer->type->label() }}</span>
                        <span class="tabular text-xs text-slate-400">{{ JalaliDate::format(CarbonImmutable::parse($newcomer->created_at)) }}</span>

                        @if ($newcomer->referral_rewarded_at)
                            <span class="ms-auto rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800">
                                خرید کرد · {{ $bonusDays }} روز هدیه گرفتید
                            </span>
                        @else
                            <span class="ms-auto text-xs text-slate-500">در دوره‌ی رایگان</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

@endsection
