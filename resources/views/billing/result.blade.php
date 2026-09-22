@extends('layouts.app')

@section('title', 'نتیجه پرداخت')

@section('content')
<div class="mx-auto max-w-md pt-10 text-center">
    <div class="rounded-2xl border bg-white p-6 {{ $successful ? 'border-emerald-200' : 'border-red-200' }}">
        <p class="text-4xl">{{ $successful ? '✓' : '✕' }}</p>

        <h1 class="mt-3 text-lg font-bold {{ $successful ? 'text-emerald-700' : 'text-red-700' }}">
            {{ $successful ? 'پرداخت موفق' : 'پرداخت ناموفق' }}
        </h1>

        <p class="mt-3 text-sm leading-7 text-slate-600">{{ $message }}</p>

        @if ($payment?->ref_num)
            {{-- The bank's reference is what a customer quotes when they ring
                 about a payment, so it is shown whether it succeeded or not. --}}
            <p class="tabular mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                شماره پیگیری بانک: <span dir="ltr">{{ $payment->ref_num }}</span>
            </p>
        @endif

        @auth
            <a href="{{ route('billing.index') }}"
               class="mt-5 inline-block rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800">
                بازگشت به صورتحساب
            </a>
        @else
            <a href="{{ route('login') }}"
               class="mt-5 inline-block rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800">
                ورود به سامانه
            </a>
        @endauth
    </div>
</div>
@endsection
