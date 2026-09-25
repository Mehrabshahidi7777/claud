@extends('layouts.app')

@section('title', 'قراردادها و مجوزها')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-[1fr_23rem]">

    <div class="min-w-0">
        <h1 class="mb-1 text-lg font-bold">قراردادها و مجوزها</h1>
        <p class="mb-4 text-sm text-slate-500">
            هر چیزی که تاریخ انقضا دارد — قرارداد کارکنان، مجوز، بیمه‌نامه،
            اجاره‌نامه. قبل از انقضا خودش تبدیل به تسک می‌شود.
        </p>

        {{-- Loudest first, and worded as the exposure it is rather than as a
             tidy status. An expired contractor qualification is not untidy;
             it loses tenders already paid for. --}}
        @if ($seriousLapses->isNotEmpty())
            <div class="mb-4 rounded-2xl border border-red-300 bg-red-50 p-4">
                <p class="font-medium text-red-900">
                    {{ $seriousLapses->count() }} مورد منقضی شده که رها کردنش گران است
                </p>
                <ul class="mt-2 space-y-1 text-sm text-red-900">
                    @foreach ($seriousLapses as $contract)
                        <li class="tabular flex flex-wrap items-baseline gap-2">
                            <a href="{{ route('contracts.show', $contract) }}" class="font-medium hover:underline">
                                {{ $contract->title }}
                            </a>
                            <span>{{ $contract->party_name }}</span>
                            <span class="ms-auto">{{ abs($contract->daysToExpiry()) }} روز گذشته</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mb-4 grid gap-3 sm:grid-cols-2">
            <div class="rounded-2xl border bg-white p-4 {{ $expired->isNotEmpty() ? 'border-red-200' : 'border-slate-200' }}">
                <p class="text-sm text-slate-500">منقضی شده</p>
                <p class="tabular mt-1 text-2xl font-bold {{ $expired->isNotEmpty() ? 'text-red-700' : '' }}">
                    {{ $expired->count() }}
                </p>
            </div>
            <div class="rounded-2xl border bg-white p-4 {{ $expiringSoon->isNotEmpty() ? 'border-amber-200' : 'border-slate-200' }}">
                <p class="text-sm text-slate-500">در آستانه‌ی انقضا</p>
                <p class="tabular mt-1 text-2xl font-bold {{ $expiringSoon->isNotEmpty() ? 'text-amber-700' : '' }}">
                    {{ $expiringSoon->count() }}
                </p>
            </div>
        </div>

        @if ($contracts->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <p class="text-slate-600">قراردادی ثبت نشده.</p>
                <p class="mt-2 text-xs text-slate-400">
                    قرارداد کارکنان، صلاحیت پیمانکاری، بیمه‌نامه — هرکدام یک ردیف.
                </p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($contracts as $contract)
                    @php
                        $expiredNow = $contract->hasExpired();
                        $soon = $contract->isExpiringSoon();
                        $days = $contract->daysToExpiry();

                        $border = ! $contract->isActive()
                            ? 'border-slate-200 opacity-60'
                            : ($expiredNow ? 'border-red-200' : ($soon ? 'border-amber-200' : 'border-slate-200'));
                    @endphp

                    <a href="{{ route('contracts.show', $contract) }}"
                       class="block rounded-2xl border bg-white p-4 hover:border-slate-400 {{ $border }}">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-medium">{{ $contract->title }}</span>
                            <span class="text-sm text-slate-500">{{ $contract->party_name }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                                {{ $contract->kind->label() }}
                            </span>

                            @unless ($contract->isActive())
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                    {{ $contract->status->label() }}
                                </span>
                            @endunless

                            @if ($contract->value)
                                <span class="tabular ms-auto text-sm font-medium">
                                    {{ number_format($contract->value) }}
                                </span>
                            @endif
                        </div>

                        <div class="tabular mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                            <span class="{{ $expiredNow ? 'font-medium text-red-600' : '' }}">
                                انقضا: {{ JalaliDate::format(CarbonImmutable::parse($contract->expires_on)) }}
                            </span>

                            @if ($contract->isActive())
                                @if ($expiredNow)
                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-red-700">
                                        {{ abs($days) }} روز گذشته
                                    </span>
                                @elseif ($soon)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">
                                        {{ $days }} روز مانده
                                    </span>
                                @endif
                            @endif

                            @if ($contract->auto_renews)
                                <span>تمدید خودکار</span>
                            @endif

                            @if ($contract->renewals > 0)
                                <span>{{ $contract->renewals }} بار تمدید شده</span>
                            @endif

                            <span class="ms-auto">{{ $contract->owner?->name ?? 'بدون مسئول' }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($canManage)
    <aside>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">قرارداد یا مجوز جدید</h2>

            <form method="POST" action="{{ route('contracts.store') }}" class="mt-3 space-y-3">
                @csrf

                <div>
                    <label for="title" class="block text-sm">عنوان</label>
                    <input id="title" name="title" required value="{{ old('title') }}"
                           placeholder="قرارداد یک‌ساله"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="kind" class="block text-sm">نوع</label>
                    <select id="kind" name="kind" data-contract-kind
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind->value }}" data-notice="{{ $kind->defaultNoticeDays() }}"
                                    @selected(old('kind') === $kind->value)>
                                {{ $kind->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="party_type" class="block text-sm">طرف قرارداد</label>
                    <select id="party_type" name="party_type"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @foreach ($partyTypes as $type)
                            <option value="{{ $type->value }}" @selected(old('party_type') === $type->value)>
                                {{ $type->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="party_user_id" class="block text-sm">
                        اگر یکی از اعضاست <span class="text-xs text-slate-400">(اختیاری)</span>
                    </label>
                    <select id="party_user_id" name="party_user_id"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        <option value="">— انتخاب کنید —</option>
                        @foreach ($members as $member)
                            <option value="{{ $member->id }}" @selected(old('party_user_id') == $member->id)>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="party_name" class="block text-sm">یا نام طرف قرارداد</label>
                    <input id="party_name" name="party_name" value="{{ old('party_name') }}"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    @error('party_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="starts_date" class="block text-sm">شروع</label>
                        <input id="starts_date" name="starts_date" required dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()) }}"
                               value="{{ old('starts_date') }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @error('starts_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="expires_date" class="block text-sm">انقضا</label>
                        <input id="expires_date" name="expires_date" required dir="ltr" placeholder="{{ \App\Support\JalaliDate::format(now()->toImmutable()->addDays(365)) }}"
                               value="{{ old('expires_date') }}"
                               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @error('expires_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div>
                    <label for="notice_days" class="block text-sm">چند روز قبلش یادآوری شود</label>
                    <input id="notice_days" name="notice_days" required inputmode="numeric" dir="ltr"
                           value="{{ old('notice_days', 60) }}" data-notice-days
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                    <p class="mt-1 text-xs text-slate-400">
                        برای قرارداد کارکنان پیش‌فرض ۶۰ روز است — یادآوری در روز
                        انقضا دیگر به درد نمی‌خورد.
                    </p>
                    @error('notice_days')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="auto_renews" value="1" class="mt-1" @checked(old('auto_renews'))>
                    <span class="text-slate-600">
                        خودبه‌خود تمدید می‌شود
                        <span class="block text-xs text-slate-400">
                            یادآوری می‌شود «تصمیم بگیرید»، نه «تمدید کنید».
                        </span>
                    </span>
                </label>

                <details class="rounded-xl border border-slate-200 p-3">
                    <summary class="cursor-pointer text-sm text-slate-700">
                        شماره، مبلغ و توضیح <span class="text-xs text-slate-400">(اختیاری)</span>
                    </summary>

                    <div class="mt-3 space-y-3">
                        <div>
                            <label for="reference" class="block text-sm">شماره قرارداد یا مجوز</label>
                            <input id="reference" name="reference" dir="ltr" value="{{ old('reference') }}"
                                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        </div>
                        <div>
                            <label for="value" class="block text-sm">مبلغ <span class="text-xs text-slate-400">(ریال)</span></label>
                            <input id="value" name="value" inputmode="numeric" dir="ltr" value="{{ old('value') }}"
                                   class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        </div>
                        <div>
                            <label for="note" class="block text-sm">توضیح</label>
                            <textarea id="note" name="note" rows="2"
                                      class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">{{ old('note') }}</textarea>
                        </div>
                    </div>
                </details>

                <div>
                    <label for="owner_id" class="block text-sm">مسئول تمدید</label>
                    <select id="owner_id" name="owner_id"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        <option value="">— خودم —</option>
                        @foreach ($members as $member)
                            <option value="{{ $member->id }}" @selected(old('owner_id') == $member->id)>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <button type="submit"
                        class="w-full rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                    ثبت
                </button>
            </form>
        </div>
    </aside>
    @endif
</div>

@endsection
