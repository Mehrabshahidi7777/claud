@extends('layouts.admin')

@section('title', 'پرداخت‌ها')

@section('content')

@php
    use App\Enums\PaymentStatus;
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <h1 class="me-auto text-lg font-bold">پرداخت‌ها</h1>

    <a href="{{ route('admin.payments.index') }}"
       class="rounded-lg px-3 py-1.5 text-sm {{ $status === null ? 'bg-slate-900 text-white' : 'bg-white ring-1 ring-slate-200' }}">همه</a>
    @foreach (PaymentStatus::cases() as $case)
        <a href="{{ route('admin.payments.index', ['status' => $case->value]) }}"
           class="rounded-lg px-3 py-1.5 text-sm {{ $status === $case->value ? 'bg-slate-900 text-white' : 'bg-white ring-1 ring-slate-200' }}">
            {{ $case->label() }}
        </a>
    @endforeach
</div>

@if ($status === PaymentStatus::Paid->value)
    <p class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
        این‌ها پول را داده‌اند ولی تأیید بانک برنگشته. اگر فردا هم اینجا بودند، با شماره‌ی پیگیری در پنل سامان بررسی کنید
        و در صورت کسر شدن پول، از صفحه‌ی مشتری روز اشتراکش را اضافه کنید.
    </p>
@endif

@if ($payments->isEmpty())
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">پرداختی نیست.</div>
@else
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-slate-200 text-xs text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">مشتری</th>
                    <th class="px-4 py-3 text-start font-medium">مبلغ</th>
                    <th class="px-4 py-3 text-start font-medium">وضعیت</th>
                    <th class="px-4 py-3 text-start font-medium">شماره‌ی مرجع</th>
                    <th class="px-4 py-3 text-start font-medium">تاریخ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($payments as $payment)
                    <tr>
                        <td class="px-4 py-3">
                            @if ($payment->workspace)
                                <a href="{{ route('admin.workspaces.show', $payment->workspace) }}" class="hover:underline">{{ $payment->workspace->name }}</a>
                            @endif
                            <span class="block text-xs text-slate-500">فاکتور {{ $payment->invoice?->number }}</span>
                        </td>
                        <td class="tabular px-4 py-3">{{ number_format(intdiv($payment->amount, 10)) }} تومان</td>
                        <td class="px-4 py-3">
                            {{ $payment->status->label() }}
                            @if ($payment->failure_reason)
                                <span class="block text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($payment->failure_reason, 60) }}</span>
                            @endif
                        </td>
                        <td class="tabular px-4 py-3 text-xs text-slate-600" dir="ltr">{{ $payment->ref_num ?? $payment->res_num }}</td>
                        <td class="tabular px-4 py-3 text-xs text-slate-500">
                            {{ JalaliDate::format(CarbonImmutable::parse($payment->created_at)) }}
                            {{ $payment->created_at->format('H:i') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>
@endif

@endsection
