@extends('layouts.app')

@section('title', 'کد ورود')

@section('content')
<div class="mx-auto max-w-sm pt-10">
    <h1 class="text-xl font-bold">کد ورود</h1>
    <p class="mt-2 text-sm text-slate-600">
        کد پنج‌رقمی به <span class="tabular font-medium" dir="ltr">{{ $phone }}</span> فرستاده شد.
        تا دو دقیقه معتبر است.
    </p>

    <form method="POST" action="{{ route('login.verify') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="code" class="block text-sm font-medium">کد</label>
            {{-- one-time-code lets both iOS and Android offer the code straight
                 from the notification, which removes the only fiddly step. --}}
            <input id="code" name="code"
                   inputmode="numeric" autocomplete="one-time-code"
                   maxlength="5" autofocus required dir="ltr"
                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-center text-2xl tracking-[0.4em] focus:border-slate-900 focus:outline-none">

            @error('code')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-slate-900 px-4 py-2.5 font-medium text-white hover:bg-slate-800">
            ورود
        </button>
    </form>

    <a href="{{ route('login') }}" class="mt-4 block text-center text-sm text-slate-500 hover:text-slate-900">
        تغییر شماره
    </a>
</div>
@endsection
