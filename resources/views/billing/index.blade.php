@extends('layouts.app')

@section('title', 'صورتحساب')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $vatPercent = config('payment.vat_percent');
    $yearlyMonths = config('payment.yearly_months_charged');
@endphp

<div class="mx-auto max-w-4xl">

    <h1 class="mb-4 text-lg font-bold">اشتراک و صورتحساب</h1>

    @if ($subscription)
        <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-baseline gap-3">
                <span class="font-medium">{{ $subscription->planName() }}</span>

                <span class="rounded-full px-2 py-0.5 text-xs
                    {{ $subscription->status->value === 'grace' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600' }}">
                    {{ $subscription->status->label() }}
                </span>

                <span class="tabular ms-auto text-sm text-slate-500">
                    تا {{ JalaliDate::format(CarbonImmutable::parse($subscription->effectiveEnd())) }}
                </span>
            </div>

            @php $days = $subscription->daysUntilExpiry(); @endphp

            @if ($days <= 7)
                {{-- Recurring card payments do not exist here, so every renewal
                     is a person remembering. The warning has to be loud. --}}
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    @if ($days >= 0)
                        اشتراک شما {{ $days }} روز دیگر تمام می‌شود. پرداخت خودکار وجود ندارد — خودتان تمدید کنید.
                    @else
                        اشتراک شما تمام شده و در مهلت ارفاق هستید. برای جلوگیری از قطع دسترسی تمدید کنید.
                    @endif
                </p>
            @endif
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($plans as $key => $plan)
            @php
                $monthly = $plan['price_per_seat'];
                $displaySeats = $plan['per_seat'] ? max($plan['min_seats'], $seats) : 1;
                $monthlyTotal = $monthly * $displaySeats;
            @endphp

            <div class="flex flex-col rounded-2xl border bg-white p-5
                        {{ $key === 'corporate' ? 'border-slate-900' : 'border-slate-200' }}">

                <h2 class="font-bold">{{ $plan['name'] }}</h2>

                <p class="tabular mt-3 text-2xl font-bold">
                    {{ number_format(intdiv($monthly, 10)) }}
                    <span class="text-sm font-normal text-slate-500">
                        تومان{{ $plan['per_seat'] ? ' / هر کاربر' : '' }} / ماه
                    </span>
                </p>

                <ul class="mt-4 flex-1 space-y-1.5 text-sm text-slate-600">
                    <li>تا {{ $plan['max_seats'] }} کاربر</li>
                    @if ($plan['per_seat'])
                        <li>حداقل {{ $plan['min_seats'] }} کاربر</li>
                    @endif
                    <li>{{ number_format($plan['included_sms']) }} پیامک در ماه</li>
                    <li>{{ $vatPercent }}٪ ارزش افزوده جداگانه</li>
                </ul>

                @if ($plan['per_seat'])
                    <p class="tabular mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        برای {{ $displaySeats }} کاربر فعلی:
                        {{ number_format(intdiv($monthlyTotal, 10)) }} تومان در ماه
                    </p>
                @endif

                <form method="POST" action="{{ route('billing.store') }}" class="mt-4 space-y-2">
                    @csrf
                    <input type="hidden" name="plan_key" value="{{ $key }}">
                    <input type="hidden" name="seats" value="{{ $displaySeats }}">

                    <button name="term" value="monthly"
                            class="w-full rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                        خرید ماهانه
                    </button>

                    <button name="term" value="yearly"
                            class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        سالانه — {{ 12 - $yearlyMonths }} ماه رایگان
                    </button>
                </form>
            </div>
        @endforeach
    </div>

    @if ($invoices->isNotEmpty())
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">فاکتورها</h2>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-500">
                        <tr class="border-b border-slate-200">
                            <th class="py-2 pe-6 text-start font-medium">شماره</th>
                            <th class="py-2 pe-6 text-start font-medium">پلن</th>
                            <th class="py-2 pe-6 text-start font-medium">مبلغ</th>
                            <th class="py-2 pe-6 text-start font-medium">وضعیت</th>
                            <th class="py-2 text-start font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td class="tabular py-2 pe-6" dir="ltr">{{ $invoice->number }}</td>
                                <td class="py-2 pe-6">{{ $invoice->planName() }}</td>
                                <td class="tabular py-2 pe-6">{{ number_format($invoice->totalInToman()) }} تومان</td>
                                <td class="py-2 pe-6">
                                    <span class="rounded-full px-2 py-0.5 text-xs
                                        {{ $invoice->status->value === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $invoice->status->label() }}
                                    </span>
                                </td>
                                <td class="py-2 text-start">
                                    <a href="{{ route('billing.invoice', $invoice) }}"
                                       class="text-sm text-slate-600 hover:text-slate-900">
                                        {{ $invoice->status->isPayable() ? 'پرداخت' : 'مشاهده' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

@endsection
