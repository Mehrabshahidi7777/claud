@extends('layouts.admin')

@section('title', 'نمای کلی')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $toman = fn (int $rial) => number_format(intdiv($rial, 10));
    $trend = $revenue['previous30'] > 0
        ? (int) round(($revenue['last30'] - $revenue['previous30']) / $revenue['previous30'] * 100)
        : null;
    $problems = $health['stuckFollowUps'] + $health['failedJobs'] + $health['unverifiedPayments'];
@endphp

{{-- Anything broken for everybody at once goes first, above the numbers. --}}
@if ($problems > 0)
    <section class="mb-6 rounded-2xl border border-red-300 bg-red-50 p-5">
        <h2 class="font-bold text-red-800">نیاز به رسیدگی فوری</h2>
        <ul class="mt-2 space-y-1 text-sm text-red-800">
            @if ($health['stuckFollowUps'] > 0)
                <li>
                    <span class="tabular font-bold">{{ number_format($health['stuckFollowUps']) }}</span>
                    پیگیری بیش از ۲۰ دقیقه است که باید می‌رفت و نرفته. کرون سرور را بررسی کنید.
                </li>
            @endif
            @if ($health['unverifiedPayments'] > 0)
                <li>
                    <span class="tabular font-bold">{{ number_format($health['unverifiedPayments']) }}</span>
                    پرداخت انجام شده ولی تأیید بانک نیامده.
                    <a href="{{ route('admin.payments.index', ['status' => 'paid']) }}" class="underline">ببینید</a>
                </li>
            @endif
            @if ($health['failedJobs'] > 0)
                <li>
                    <span class="tabular font-bold">{{ number_format($health['failedJobs']) }}</span>
                    کار صف شکست خورده (معمولاً پاسخ پیامکی). <code dir="ltr">php artisan queue:failed</code>
                </li>
            @endif
        </ul>
    </section>
@endif

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm text-slate-500">درآمد ۳۰ روز اخیر</h2>
        <p class="tabular mt-2 text-2xl font-bold">{{ $toman($revenue['last30']) }}</p>
        <p class="mt-1 text-xs text-slate-500">
            تومان، با ارزش افزوده
            @if ($trend !== null)
                <span class="tabular {{ $trend >= 0 ? 'text-emerald-700' : 'text-red-600' }}">
                    ({{ $trend >= 0 ? '+' : '' }}{{ $trend }}٪ نسبت به ۳۰ روز قبل)
                </span>
            @endif
        </p>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm text-slate-500">درآمد ماهانه‌ی تکرارشونده</h2>
        <p class="tabular mt-2 text-2xl font-bold">{{ $toman($revenue['mrr']) }}</p>
        <p class="mt-1 text-xs text-slate-500">تومان در ماه، از اشتراک‌های فعال، بدون ارزش افزوده</p>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm text-slate-500">مشتری‌ها</h2>
        <p class="tabular mt-2 text-2xl font-bold">{{ number_format($customers['total']) }}</p>
        <p class="mt-1 text-xs text-slate-500">
            <span class="tabular">{{ number_format($customers['new']) }}</span> تازه در ۳۰ روز ·
            <span class="tabular">{{ number_format($customers['users']) }}</span> کاربر
        </p>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm text-slate-500">کل درآمد تا امروز</h2>
        <p class="tabular mt-2 text-2xl font-bold">{{ $toman($revenue['total']) }}</p>
        <p class="mt-1 text-xs text-slate-500">تومان، فاکتورهای پرداخت‌شده</p>
    </section>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="font-medium">وضعیت اشتراک‌ها</h2>
        <div class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
            @foreach ([
                ['active', 'فعال', 'bg-emerald-50 text-emerald-800'],
                ['trialing', 'آزمایشی', 'bg-sky-50 text-sky-800'],
                ['grace', 'مهلت ارفاق', 'bg-amber-50 text-amber-800'],
                ['lapsed', 'منقضی', 'bg-slate-100 text-slate-700'],
            ] as [$key, $label, $tone])
                <a href="{{ route('admin.workspaces.index', ['status' => $key]) }}" class="rounded-xl p-3 {{ $tone }} hover:ring-2 hover:ring-slate-300">
                    <span class="tabular block text-xl font-bold">{{ number_format($subscriptions[$key]) }}</span>
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <h3 class="mt-5 text-sm font-medium text-slate-500">به تفکیک پلن</h3>
        <div class="mt-2 flex flex-wrap gap-2 text-sm">
            @foreach ($customers['byType'] as $label => $count)
                <span class="rounded-full bg-slate-100 px-3 py-1">{{ $label }}: <span class="tabular font-medium">{{ number_format($count) }}</span></span>
            @endforeach
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="flex items-baseline gap-2">
            <h2 class="font-medium">پیامک</h2>
            <a href="{{ route('admin.sms.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">همه</a>
        </div>
        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex gap-2">
                <dt class="text-slate-500">اعتبار باقی‌مانده‌ی پنل</dt>
                <dd class="tabular ms-auto font-medium">
                    {{ $sms['credit'] === null ? 'نامشخص (درایور لاگ یا پنل در دسترس نیست)' : number_format($sms['credit']) }}
                </dd>
            </div>
            <div class="flex gap-2">
                <dt class="text-slate-500">پیامک پیگیری فرستاده‌شده، ۳۰ روز</dt>
                <dd class="tabular ms-auto font-medium">{{ number_format($sms['sentSegments']) }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="text-slate-500">ارسال ناموفق، ۷ روز</dt>
                <dd class="tabular ms-auto font-medium {{ $sms['failed'] > 0 ? 'text-red-600' : '' }}">
                    @if ($sms['failed'] > 0)
                        <a href="{{ route('admin.sms.index', ['status' => 'failed']) }}" class="underline">{{ number_format($sms['failed']) }}</a>
                    @else
                        ۰
                    @endif
                </dd>
            </div>
        </dl>
    </section>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    @foreach ([
        ['آزمایشی‌هایی که تا ۷ روز دیگر تمام می‌شوند', 'همین حالا زنگ بزنید؛ فردا دیر است.', $trialsEnding],
        ['تمدیدهای ۱۴ روز آینده', 'پرداخت خودکار نداریم؛ اگر یادشان نیندازید، نمی‌مانند.', $renewalsDue],
    ] as [$title, $hint, $rows])
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">{{ $title }}</h2>
            <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>

            @if ($rows->isEmpty())
                <p class="mt-3 text-sm text-slate-400">موردی نیست.</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($rows as $subscription)
                        @php $owner = $subscription->workspace->owners->first(); @endphp
                        <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2">
                            <a href="{{ route('admin.workspaces.show', $subscription->workspace) }}" class="font-medium hover:underline">
                                {{ $subscription->workspace->name }}
                            </a>
                            <span class="text-xs text-slate-500">{{ $owner?->name }}</span>
                            <span class="tabular text-xs text-slate-500" dir="ltr">{{ $owner?->localPhone() }}</span>
                            <span class="tabular ms-auto text-xs {{ $subscription->ends_at->isPast() ? 'text-red-600' : 'text-amber-700' }}">
                                {{ JalaliDate::format(CarbonImmutable::parse($subscription->ends_at)) }}
                                · {{ $subscription->ends_at->diffForHumans() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>

@endsection
