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
    <div class="flex items-center gap-2">
        <img src="/icons/logo.svg" alt="" class="size-10" width="40" height="40">
        <span class="text-sm font-medium text-slate-900">{{ config('brand.name') }}</span>

        <button type="button" data-welcome-open
                class="ms-auto hidden rounded-lg px-2 py-1 text-xs text-slate-500 hover:bg-slate-100 hover:text-slate-900">
            پیگیر چیست؟
        </button>
    </div>
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

{{-- What it is, in three steps, before any prices. Short on purpose: a
     visitor should get it from the headings alone. --}}
<section class="mx-auto mt-12 max-w-4xl">
    <h2 class="mx-auto max-w-sm text-base font-bold md:max-w-none md:text-center">پیگیر چطور کار می‌کند؟</h2>

    <ol class="mx-auto mt-4 grid max-w-sm gap-3 md:max-w-none md:grid-cols-3">
        @foreach ([
            ['کار را ثبت کنید', 'چه کاری، به عهده‌ی چه کسی، تا کی. یک جمله کافی است.'],
            ['پیگیر پیگیری می‌کند', 'سر موعد به آن آدم پیامک می‌زند؛ لازم نیست برنامه‌ای نصب کند.'],
            ['کار بسته می‌شود', 'با فرستادن «۱» کار تمام‌شده ثبت می‌شود و شما خبردار می‌شوید.'],
        ] as $index => [$title, $text])
            <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                <span class="tabular grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-bold text-brand-700">{{ $index + 1 }}</span>
                <div>
                    <p class="font-medium">{{ $title }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $text }}</p>
                </div>
            </li>
        @endforeach
    </ol>
</section>

{{-- The three plans as cards to swipe through. Wider than the form so the
     next card peeks in from the edge, which is what tells a thumb it can
     swipe. Native scroll snapping: no library, and it still works as a plain
     scrolling row with JavaScript off. --}}
<section class="mx-auto mt-12 max-w-4xl" data-plan-cards>
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
{{-- The first-visit welcome, the way an installed app greets you: three
     short slides, then the login page. Hidden in the markup and opened by
     app.js, so without JavaScript (or for a returning visitor) the page is
     simply the page. --}}
<div class="fixed inset-0 z-50 hidden" data-welcome
     role="dialog" aria-modal="true" aria-label="معرفی {{ config('brand.name') }}">
  <div class="flex h-full items-center justify-center bg-slate-900/40 md:p-6">
    <div class="flex h-full w-full flex-col bg-white outline-none md:h-auto md:max-w-md md:rounded-3xl md:shadow-xl" tabindex="-1" data-welcome-panel>
        <div class="flex items-center justify-between px-5 pt-5">
            <img src="/icons/logo.svg" alt="" class="size-8" width="32" height="32">
            <button type="button" data-welcome-close class="rounded-lg px-2 py-1 text-sm text-slate-500 hover:bg-slate-100">رد شدن</button>
        </div>

        <div class="flex flex-1 snap-x snap-mandatory overflow-x-auto scroll-smooth [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-welcome-track>

            <section class="flex w-full shrink-0 snap-center flex-col justify-center px-8 py-6 text-center" data-welcome-slide>
                <img src="/icons/logo.svg" alt="" class="mx-auto size-24" width="96" height="96">
                <h2 class="mt-8 text-2xl font-bold">{{ config('brand.slogan') }}</h2>
                <p class="mt-3 leading-7 text-slate-600">
                    پیگیر کارهایی را که به دیگران سپرده‌اید دنبال می‌کند تا انجام شوند؛
                    بدون زنگ زدن، بدون «چی شد؟».
                </p>
            </section>

            <section class="flex w-full shrink-0 snap-center flex-col justify-center px-8 py-6" data-welcome-slide>
                <div class="mx-auto w-full max-w-72 space-y-2 rounded-3xl bg-slate-100 p-4 text-sm">
                    <p class="max-w-[85%] rounded-2xl rounded-ss-sm bg-white px-3 py-2 leading-6 shadow-sm">
                        «تمدید بیمه‌ی ماشین» فردا سررسید است. انجام شد؟ عدد ۱ را بفرستید.
                    </p>
                    <p class="ms-auto w-fit rounded-2xl rounded-se-sm bg-brand-600 px-4 py-2 font-medium text-white">۱</p>
                    <p class="text-center text-xs text-emerald-700">✓ کار بسته شد و به شما خبر رسید</p>
                </div>
                <h2 class="mt-8 text-center text-2xl font-bold">با یک پیامک</h2>
                <p class="mt-3 text-center leading-7 text-slate-600">
                    طرف مقابل لازم نیست برنامه‌ای نصب کند. یادآوری را پیامک می‌گیرد و
                    با یک عدد جواب می‌دهد.
                </p>
            </section>

            <section class="flex w-full shrink-0 snap-center flex-col justify-center px-8 py-6 text-center" data-welcome-slide>
                <div class="mx-auto flex flex-wrap justify-center gap-2 text-sm">
                    @foreach (\App\Enums\WorkspaceType::cases() as $type)
                        <span class="rounded-full border border-slate-200 px-4 py-2 font-medium">{{ $type->label() }}</span>
                    @endforeach
                </div>
                <h2 class="mt-8 text-2xl font-bold">برای کار، خانه و دوستان</h2>
                <p class="mt-3 leading-7 text-slate-600">
                    کارهای شرکت، قبض‌ها و سرویس‌های خانه، یا خرج مشترک سفر با دوستان.
                </p>
                <p class="mx-auto mt-5 w-fit rounded-xl bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-800">
                    {{ $freeDays }} روز رایگان، بدون پرداخت
                </p>
            </section>
        </div>

        <div class="flex items-center gap-3 px-5 pt-2 pb-6">
            <div class="flex gap-2" aria-hidden="true">
                @for ($i = 0; $i < 3; $i++)
                    <span class="h-2 w-2 rounded-full bg-slate-300 transition-all data-[active]:w-5 data-[active]:bg-brand-600" data-welcome-dot></span>
                @endfor
            </div>
            <button type="button" data-welcome-next
                    class="ms-auto rounded-xl bg-brand-700 px-6 py-2.5 font-medium text-white hover:bg-brand-800">
                بعدی
            </button>
        </div>
    </div>
  </div>
</div>
@endsection
