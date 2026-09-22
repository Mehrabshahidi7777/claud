@extends('layouts.app')

@section('title', 'فاکتور ' . $invoice->number)

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto max-w-2xl">

    <div class="rounded-2xl border border-slate-200 bg-white p-6">

        <div class="flex flex-wrap items-start gap-3 border-b border-slate-100 pb-4">
            <div>
                <h1 class="text-lg font-bold">فاکتور فروش</h1>
                <p class="tabular mt-1 text-sm text-slate-500" dir="ltr">{{ $invoice->number }}</p>
            </div>

            <div class="ms-auto text-start text-sm text-slate-500">
                <p class="tabular">
                    تاریخ صدور:
                    {{ JalaliDate::format(CarbonImmutable::parse($invoice->created_at)) }}
                </p>
                <p class="mt-1">
                    <span class="rounded-full px-2 py-0.5 text-xs
                        {{ $invoice->status->value === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                        {{ $invoice->status->label() }}
                    </span>
                </p>
            </div>
        </div>

        <div class="grid gap-4 py-4 text-sm sm:grid-cols-2">
            <div>
                <p class="text-xs text-slate-400">خریدار</p>
                <p class="mt-1 font-medium">{{ $invoice->legal_name ?: $workspace->name }}</p>
                @if ($invoice->national_id)
                    <p class="tabular mt-1 text-slate-500">شناسه ملی: {{ $invoice->national_id }}</p>
                @endif
                @if ($invoice->economic_code)
                    <p class="tabular mt-1 text-slate-500">کد اقتصادی: {{ $invoice->economic_code }}</p>
                @endif
                @if ($invoice->address)
                    <p class="mt-1 text-slate-500">{{ $invoice->address }}</p>
                @endif
            </div>

            <div>
                <p class="text-xs text-slate-400">دوره</p>
                <p class="tabular mt-1">
                    {{ JalaliDate::format(CarbonImmutable::parse($invoice->period_start)) }}
                    تا
                    {{ JalaliDate::format(CarbonImmutable::parse($invoice->period_end)) }}
                </p>
            </div>
        </div>

        <table class="w-full border-t border-slate-100 text-sm">
            <thead class="text-xs text-slate-500">
                <tr class="border-b border-slate-100">
                    <th class="py-2 text-start font-medium">شرح</th>
                    <th class="py-2 text-start font-medium">تعداد</th>
                    <th class="py-2 text-start font-medium">مبلغ</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="py-3">
                        اشتراک {{ $invoice->planName() }}
                        <span class="text-slate-500">
                            ({{ $invoice->term === 'yearly' ? 'سالانه' : 'ماهانه' }})
                        </span>
                    </td>
                    <td class="tabular py-3">{{ $invoice->seats }} کاربر</td>
                    <td class="tabular py-3">{{ number_format($invoice->subtotalInToman()) }} تومان</td>
                </tr>
            </tbody>
        </table>

        <div class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm">
            <div class="flex justify-between">
                <span class="text-slate-500">جمع</span>
                <span class="tabular">{{ number_format($invoice->subtotalInToman()) }} تومان</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500">ارزش افزوده ({{ $invoice->vat_percent }}٪)</span>
                <span class="tabular">{{ number_format($invoice->vatInToman()) }} تومان</span>
            </div>
            <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-bold">
                <span>قابل پرداخت</span>
                <span class="tabular">{{ number_format($invoice->totalInToman()) }} تومان</span>
            </div>
        </div>

        @error('payment')
            <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>
        @enderror

        @if ($invoice->status->isPayable())
            <form method="POST" action="{{ route('billing.pay', $invoice) }}" class="mt-5">
                @csrf
                <button type="submit"
                        class="w-full rounded-xl bg-slate-900 px-4 py-3 font-medium text-white hover:bg-slate-800">
                    پرداخت از درگاه بانک سامان
                </button>
            </form>
        @else
            <p class="tabular mt-5 rounded-lg bg-emerald-50 px-3 py-2 text-center text-sm text-emerald-800">
                پرداخت‌شده در {{ JalaliDate::format(CarbonImmutable::parse($invoice->paid_at)) }}
            </p>
        @endif
    </div>

    <p class="mt-3 text-center text-xs text-slate-400">
        <a href="{{ route('billing.index') }}" class="hover:text-slate-900">بازگشت به صورتحساب</a>
    </p>
</div>

@endsection
