@extends('layouts.app')

@section('title', 'ورود')

@section('content')
<div class="mx-auto max-w-sm pt-10">
    {{-- The sign-in screen is the one page a prospect sees before they have
         any reason to care, so it carries the name and the promise. --}}
    <p class="text-sm font-medium text-slate-900">{{ config('brand.name') }}</p>
    <h1 class="mt-1 text-xl font-bold">{{ config('brand.slogan') }}</h1>

    <p class="mt-2 text-sm text-slate-600">
        شماره موبایل خود را بفرستید. کد ورود پیامک می‌شود — نه رمزی هست، نه
        ایمیلی.
    </p>

    <form method="POST" action="{{ route('login.request') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="phone" class="block text-sm font-medium">شماره موبایل</label>
            <input id="phone" name="phone" value="{{ old('phone') }}"
                   inputmode="tel" autocomplete="tel" autofocus required
                   placeholder="09121234567"
                   dir="ltr"
                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-center focus:border-slate-900 focus:outline-none">

            @error('phone')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-slate-900 px-4 py-2.5 font-medium text-white hover:bg-slate-800">
            فرستادن کد
        </button>
    </form>
</div>
@endsection
