@extends('layouts.app')

@section('title', 'شروع')

@section('content')
<div class="mx-auto max-w-lg pt-10">
    <h1 class="text-xl font-bold">آخرین قدم</h1>
    <p class="mt-2 text-sm text-slate-600">
        نام خودتان، و اینکه اینجا قرار است چه چیزی را جمع کند.
    </p>

    @if ($referralBonusDays > 0)
        <p class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            با معرفی دوستتان آمده‌اید؛ {{ $referralBonusDays }} روز به دوره‌ی رایگانتان اضافه می‌شود.
        </p>
    @endif

    <form method="POST" action="{{ route('onboarding.store') }}" class="mt-6 space-y-5">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">نام و نام خانوادگی</label>
            <input id="name" name="name" value="{{ old('name') }}" autofocus required
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        {{-- The most consequential answer on this page. It decides which
             modules exist, whether an unanswered reminder climbs to somebody
             else, and what the people here are called — so it is asked
             plainly, with the consequence written next to each option. --}}
        <fieldset>
            <legend class="block text-sm font-medium">اینجا کجاست؟</legend>

            <div class="mt-2 space-y-2">
                @foreach ($types as $type)
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-300 p-3 hover:border-slate-900 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                        <input type="radio" name="type" value="{{ $type->value }}" required class="mt-1"
                               @checked(old('type', 'corporate') === $type->value)>
                        <span>
                            <span class="block text-sm font-medium">{{ $type->label() }}</span>
                            <span class="block text-xs leading-6 text-slate-500">{{ $type->tagline() }}</span>

                            @unless ($type->hasEscalation())
                                <span class="mt-1 block text-xs text-slate-400">
                                    بدون تشدید به مدیر — یادآوری فقط به خودِ شخص می‌رسد.
                                </span>
                            @endunless
                        </span>
                    </label>
                @endforeach
            </div>

            @error('type')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </fieldset>

        <div>
            <label for="workspace" class="block text-sm font-medium">اسمش را چه بگذاریم؟</label>
            <input id="workspace" name="workspace" value="{{ old('workspace') }}" required
                   placeholder="تأسیسات پارس"
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            @error('workspace')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs leading-6 text-slate-500">
            این انتخاب بعداً هم قابل تغییر است، ولی الان درست انتخاب کنید تا از
            همان اول فقط چیزهایی را ببینید که به دردتان می‌خورد.
        </p>

        <button type="submit"
                class="w-full rounded-xl bg-brand-700 px-4 py-2.5 font-medium text-white hover:bg-brand-800">
            بساز و شروع کن
        </button>
    </form>
</div>
@endsection
