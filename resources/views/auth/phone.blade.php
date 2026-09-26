@extends('layouts.app')

@section('title', 'ورود')

@section('content')

@php
    use App\Enums\WorkspaceType;

    $plans = config('payment.plans');
    $toman = fn (int $rial) => number_format(intdiv($rial, 10));

    // Someone who arrived through a friend's link sees the longer trial that
    // link promised them.
    $isReferred = session()->has('referral_code');
    $freeDays = (int) config('payment.trial_days') + ($isReferred ? (int) config('payment.referral_bonus_days') : 0);

    // What each plan is for, in the words of the person it is for. The
    // modules behind each list live in WorkspaceType; this is only the pitch.
    $cards = [
        [
            'type' => WorkspaceType::Corporate,
            'points' => [
                'کار را به همکار بسپارید؛ پیگیری با پیامک خودکار است',
                'اگر جواب ندهد، خبرش به مدیرش می‌رسد',
                'جلسه‌ها، تأییدیه‌ها، قراردادها و حساب‌ها یک‌جا',
                'گزارش هفتگی: چه کسی کارها را به‌موقع بست',
            ],
            'price' => $toman($plans['corporate']['price_per_seat']).' تومان برای هر نفر در ماه، از '.$plans['corporate']['min_seats'].' نفر',
        ],
        [
            'type' => WorkspaceType::Family,
            'points' => [
                'قبض‌ها و تمدیدها سر موعد یادآوری می‌شوند',
                'کارهای تکراری مثل سرویس ماشین خودشان ساخته می‌شوند',
                'هر کس فقط یادآوری کار خودش را می‌گیرد',
                'تا '.$plans['family']['max_seats'].' نفر',
            ],
            'price' => $toman($plans['family']['price_per_seat']).' تومان در ماه',
        ],
        [
            'type' => WorkspaceType::Friends,
            'points' => [
                'خرج سفر و دورهمی را ثبت کنید؛ سهم هر کس حساب می‌شود',
                'یادآوری بدهی، بدون رودربایستی',
                'قرارها و کارهای گروهی تقسیم می‌شوند',
                'تا '.$plans['friends']['max_seats'].' نفر',
            ],
            'price' => $toman($plans['friends']['price_per_seat']).' تومان در ماه',
        ],
    ];
@endphp

<div class="mx-auto max-w-sm pt-10">
    {{-- The sign-in screen is the one page a prospect sees before they have
         any reason to care, so it carries the name and the promise. The form
         stays near the top: most visits are people coming back. --}}
    <p class="flex items-center gap-2 text-sm font-medium text-slate-900">
        <img src="/icons/logo.svg" alt="" class="size-10" width="40" height="40">
        {{ config('brand.name') }}
    </p>
    <h1 class="mt-3 text-xl font-bold">{{ config('brand.slogan') }}</h1>

    <p class="mt-3 inline-flex rounded-xl bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-800">
        {{ $freeDays }} روز رایگان{{ $isReferred ? ' با معرفی دوستتان' : '' }}، روی هر سه پلن — بدون پرداخت
    </p>

    @if ($isHeadingToInvite)
        <p class="mt-3 rounded-xl bg-brand-50 px-3 py-2 text-sm text-brand-900">
            وارد شوید تا لینک دعوت اختصاصی‌تان را بگیرید.
        </p>
    @endif

    <p class="mt-3 text-sm text-slate-600">
        شماره موبایل خود را وارد کنید تا کد ورود برایتان پیامک شود.
    </p>

    <form method="POST" action="{{ route('login.request') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="phone" class="block text-sm font-medium">شماره موبایل</label>
            <input id="phone" name="phone" value="{{ old('phone') }}"
                   inputmode="tel" autocomplete="tel" autofocus required
                   placeholder="0913…"
                   dir="ltr"
                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-center focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">

            @error('phone')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-brand-700 px-4 py-2.5 font-medium text-white hover:bg-brand-800">
            فرستادن کد
        </button>
    </form>

    @unless ($isHeadingToInvite)
        <a href="{{ route('login', ['next' => 'invite']) }}"
           class="mt-3 flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
            <svg class="size-5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>
            </svg>
            دعوت از دوستان و هدیه گرفتن
        </a>
    @endunless
</div>

{{-- The three plans as cards to swipe through. Wider than the form so the
     next card peeks in from the edge, which is what tells a thumb it can
     swipe. Native scroll snapping: no library, and it still works as a plain
     scrolling row with JavaScript off. --}}
<section class="mx-auto mt-10 max-w-4xl" data-plan-cards>
    <h2 class="mx-auto max-w-sm text-base font-bold">سه پلن، برای سه جور کار</h2>
    <p class="mx-auto mt-1 max-w-sm text-sm text-slate-600">
        بعد از ورود یکی را انتخاب می‌کنید.<span class="md:hidden"> کارت‌ها را بکشید تا بقیه را ببینید.</span>
    </p>

    {{-- On a wide screen all three fit, so they simply sit side by side. --}}
    <div class="-mx-4 mt-4 flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth px-4 pb-2 [scrollbar-width:none] md:mx-0 md:grid md:grid-cols-3 md:overflow-visible md:px-0 [&::-webkit-scrollbar]:hidden"
         data-plan-track>
        @foreach ($cards as $card)
            <article class="flex w-[85%] shrink-0 snap-center flex-col rounded-2xl border border-slate-200 bg-white p-5 sm:w-72 md:w-auto"
                     data-plan-card>
                <p class="text-lg font-bold">{{ $card['type']->label() }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $card['type']->tagline() }}</p>

                <ul class="mt-4 space-y-2 text-sm text-slate-700">
                    @foreach ($card['points'] as $point)
                        <li class="flex gap-2">
                            <svg class="mt-0.5 size-4 shrink-0 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                            </svg>
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>

                <p class="tabular mt-auto border-t border-slate-100 pt-4 text-sm font-medium text-slate-900">
                    {{ $card['price'] }}
                </p>
            </article>
        @endforeach
    </div>

    <div class="mt-3 flex justify-center gap-2 md:hidden" aria-hidden="true">
        @foreach ($cards as $card)
            <button type="button" data-plan-dot tabindex="-1"
                    class="h-2 w-2 rounded-full bg-slate-300 transition-all data-[active]:w-5 data-[active]:bg-brand-600"></button>
        @endforeach
    </div>
</section>
@endsection
