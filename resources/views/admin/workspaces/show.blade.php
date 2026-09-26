@extends('layouts.admin')

@section('title', $workspace->name)

@section('content')

@php
    use App\Enums\WorkspaceRole;
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $date = fn ($value) => $value ? JalaliDate::format(CarbonImmutable::parse($value)) : '—';
    $toman = fn (int $rial) => number_format(intdiv($rial, 10));
    $owner = $workspace->members->first(fn ($member) => $member->pivot->role === WorkspaceRole::Owner->value);
@endphp

<a href="{{ route('admin.workspaces.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← همه‌ی مشتری‌ها</a>

<div class="mt-3 flex flex-wrap items-baseline gap-3">
    <h1 class="text-xl font-bold">{{ $workspace->name }}</h1>
    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs">{{ $workspace->type->label() }}</span>
    <span class="tabular text-sm text-slate-500">عضو از {{ $date($workspace->created_at) }}</span>
    @if ($workspace->referrer)
        <span class="text-sm text-slate-500">
            معرفی‌شده توسط
            <a href="{{ route('admin.workspaces.show', $workspace->referrer) }}" class="text-slate-700 hover:underline">{{ $workspace->referrer->name }}</a>
        </span>
    @endif
    @if ($owner)
        <span class="ms-auto text-sm">
            مالک: {{ $owner->name ?: '—' }}
            <span class="tabular text-slate-500" dir="ltr">{{ $owner->localPhone() }}</span>
        </span>
    @endif
</div>

<div class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
    @foreach ([
        ['اعضا', $workspace->members->count(), ''],
        ['کار باز', $tasks['open'], ''],
        ['عقب‌افتاده', $tasks['overdue'], $tasks['overdue'] > 0 ? 'text-red-600' : ''],
        ['بسته‌شده، ۳۰ روز', $tasks['done30'], 'text-emerald-700'],
        ['پیامک، ۳۰ روز', $sms['sent30'], ''],
        ['پیامک ناموفق', $sms['failed30'], $sms['failed30'] > 0 ? 'text-red-600' : ''],
    ] as [$label, $value, $tone])
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs text-slate-500">{{ $label }}</p>
            <p class="tabular mt-1 text-xl font-bold {{ $tone }}">{{ number_format($value) }}</p>
        </div>
    @endforeach
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-[1fr_20rem]">
    <div class="min-w-0 space-y-4">

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">اعضا</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[480px] text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($workspace->members as $member)
                            <tr>
                                <td class="px-2 py-2">{{ $member->name ?: '— بی‌نام —' }}</td>
                                <td class="tabular px-2 py-2 text-slate-500" dir="ltr">{{ $member->localPhone() }}</td>
                                <td class="px-2 py-2 text-slate-600">{{ WorkspaceRole::from($member->pivot->role)->label() }}</td>
                                <td class="px-2 py-2 text-xs">
                                    @if ($member->hasOptedOutOfSms())
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">پیامک قطع</span>
                                    @elseif (! $member->hasVerifiedPhone())
                                        <span class="text-slate-400">هنوز وارد نشده</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">فاکتورها و پرداخت‌ها</h2>

            @if ($workspace->invoices->isEmpty())
                <p class="mt-2 text-sm text-slate-400">هنوز فاکتوری صادر نشده.</p>
            @else
                <table class="mt-3 w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($workspace->invoices as $invoice)
                            <tr>
                                <td class="tabular px-2 py-2">{{ $invoice->number }}</td>
                                <td class="px-2 py-2 text-slate-600">{{ $invoice->planName() }} · {{ $invoice->term === 'yearly' ? 'سالانه' : 'ماهانه' }}</td>
                                <td class="tabular px-2 py-2">{{ $toman($invoice->total) }} تومان</td>
                                <td class="px-2 py-2 text-xs {{ $invoice->status->value === 'paid' ? 'text-emerald-700' : 'text-slate-500' }}">{{ $invoice->status->label() }}</td>
                                <td class="tabular px-2 py-2 text-xs text-slate-500">{{ $date($invoice->paid_at ?? $invoice->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($workspace->payments->isNotEmpty())
                <h3 class="mt-5 text-sm font-medium text-slate-500">تلاش‌های پرداخت</h3>
                <table class="mt-2 w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($workspace->payments as $payment)
                            <tr>
                                <td class="tabular px-2 py-2 text-xs" dir="ltr">{{ $payment->res_num }}</td>
                                <td class="tabular px-2 py-2">{{ $toman($payment->amount) }} تومان</td>
                                <td class="px-2 py-2 text-xs">{{ $payment->status->label() }}</td>
                                <td class="px-2 py-2 text-xs text-slate-500">{{ $payment->failure_reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">آخرین رویدادها</h2>
            @if ($activities->isEmpty())
                <p class="mt-2 text-sm text-slate-400">رویدادی ثبت نشده.</p>
            @else
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($activities as $activity)
                        <li class="flex flex-wrap items-baseline gap-x-2">
                            <span>{{ $activity->label() }}</span>
                            <span class="text-xs text-slate-400">{{ $activity->user?->name ?: 'سامانه' }}</span>
                            <span class="tabular ms-auto text-xs text-slate-400">{{ $date($activity->created_at) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <aside class="space-y-4">
        <section class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">اشتراک</h2>

            @if ($current)
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex gap-2"><dt class="text-slate-500">پلن</dt><dd class="ms-auto">{{ $current->planName() }}</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500">وضعیت</dt><dd class="ms-auto">{{ $current->status->label() }}</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500">تا</dt><dd class="tabular ms-auto">{{ $date($current->ends_at) }}</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500">مانده</dt><dd class="ms-auto">{{ $current->ends_at->diffForHumans() }}</dd></div>
                </dl>
            @else
                <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    اشتراک فعالی ندارد؛ سامانه برایش فقط‌خواندنی است.
                </p>
            @endif

            <form method="POST" action="{{ route('admin.workspaces.extend', $workspace) }}" class="mt-4 border-t border-slate-100 pt-4">
                @csrf
                <label for="days" class="block text-sm">افزودن روز</label>
                <p class="text-xs text-slate-500">به انتهای اشتراک فعلی اضافه می‌شود. منقضی‌ها فعال می‌شوند.</p>
                <div class="mt-2 flex gap-2">
                    <input id="days" name="days" type="number" min="1" max="365" value="30" required
                           class="tabular w-24 rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <button class="flex-1 rounded-xl bg-brand-700 px-3 py-2 text-sm font-medium text-white hover:bg-brand-800">
                        اضافه کن
                    </button>
                </div>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">پیامک</h2>
            <p class="tabular mt-1 text-sm text-slate-600">
                {{ number_format($workspace->sms_used) }} از {{ number_format($workspace->sms_quota) }} مصرف شده
            </p>

            <form method="POST" action="{{ route('admin.workspaces.sms', $workspace) }}" class="mt-3 space-y-3">
                @csrf
                <div>
                    <label for="sms_quota" class="block text-sm">سهمیه‌ی ماهانه</label>
                    <input id="sms_quota" name="sms_quota" type="number" min="0" max="1000000" value="{{ $workspace->sms_quota }}" required
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="sms_enabled" value="1" @checked($workspace->sms_enabled)>
                    ارسال پیامک روشن باشد
                </label>
                <button class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">ذخیره</button>
            </form>
        </section>

        @if ($workspace->subscriptions->count() > 1)
            <section class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
                <h2 class="font-medium">تاریخچه‌ی اشتراک</h2>
                <ul class="mt-2 space-y-1 text-slate-600">
                    @foreach ($workspace->subscriptions as $subscription)
                        <li class="flex gap-2">
                            <span>{{ $subscription->status->label() }}</span>
                            <span class="tabular ms-auto text-xs">{{ $date($subscription->starts_at) }} ← {{ $date($subscription->ends_at) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </aside>
</div>

@endsection
