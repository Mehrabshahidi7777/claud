@extends('layouts.app')

@section('title', 'حساب‌وکتاب')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $me = auth()->id();
    $myNet = $balances->firstWhere('user.id', $me)['net'] ?? 0;
@endphp

<div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-[1fr_23rem]">

    <div class="min-w-0">
        <h1 class="mb-1 text-lg font-bold">حساب‌وکتاب</h1>
        <p class="mb-4 text-sm text-slate-500">
            خرج‌ها را ثبت کنید، بقیه‌اش با سامانه. هیچ‌کس مجبور نیست بپرسد.
        </p>

        {{-- The viewer's own position, said in one sentence before any table.
             It is the only line most people read. --}}
        <div class="mb-4 rounded-2xl border bg-white p-5 {{ $myNet < 0 ? 'border-amber-300' : ($myNet > 0 ? 'border-emerald-200' : 'border-slate-200') }}">
            @if ($myNet === 0)
                <p class="text-lg font-bold text-emerald-700">حساب شما صاف است.</p>
            @elseif ($myNet > 0)
                <p class="text-sm text-slate-500">از بقیه طلبکارید</p>
                <p class="tabular mt-1 text-2xl font-bold text-emerald-700">
                    {{ number_format($myNet) }}
                    <span class="text-sm font-normal text-slate-400">ریال</span>
                </p>
            @else
                <p class="text-sm text-slate-500">بدهکارید</p>
                <p class="tabular mt-1 text-2xl font-bold text-amber-700">
                    {{ number_format(abs($myNet)) }}
                    <span class="text-sm font-normal text-slate-400">ریال</span>
                </p>
            @endif
        </div>

        {{-- The answer, not the data. Six friends after a trip owe each other
             in a tangle nobody untangles, so nobody pays. --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">کوتاه‌ترین راه تسویه</h2>

            @if (empty($transfers))
                <p class="mt-3 text-sm text-emerald-700">
                    همه صاف‌اند. چیزی برای تسویه نیست.
                </p>
            @else
                <p class="mt-1 text-xs text-slate-500">
                    با همین {{ count($transfers) }} پرداخت، حساب همه صاف می‌شود.
                </p>

                <ul class="mt-3 space-y-2">
                    @foreach ($transfers as $transfer)
                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1.5 rounded-xl border border-slate-200 p-3 text-sm">
                            <span class="font-medium">{{ $transfer['from']->name }}</span>
                            <span class="text-slate-400">←</span>
                            <span class="font-medium">{{ $transfer['to']->name }}</span>

                            <span class="tabular ms-auto font-bold">
                                {{ number_format($transfer['amount']) }}
                                <span class="text-xs font-normal text-slate-400">ریال</span>
                            </span>

                            @if ($transfer['from']->id === $me || $transfer['to']->id === $me)
                                <form method="POST" action="{{ route('settlements.settle') }}" class="w-full">
                                    @csrf
                                    <input type="hidden" name="from_user_id" value="{{ $transfer['from']->id }}">
                                    <input type="hidden" name="to_user_id" value="{{ $transfer['to']->id }}">
                                    <input type="hidden" name="amount" value="{{ $transfer['amount'] }}">
                                    <button class="mt-1 w-full rounded-lg bg-emerald-600 px-3 py-1.5 text-xs text-white hover:bg-emerald-700">
                                        پرداخت شد
                                    </button>
                                </form>
                            @elseif ($transfer['to']->id !== $me)
                                {{-- Only offered to the person owed. Nobody
                                     should be able to start the engine
                                     chasing a friend on a third party's
                                     behalf. --}}
                            @endif

                            @if ($transfer['to']->id === $me)
                                <form method="POST" action="{{ route('settlements.remind') }}" class="w-full">
                                    @csrf
                                    <input type="hidden" name="from_user_id" value="{{ $transfer['from']->id }}">
                                    <input type="hidden" name="to_user_id" value="{{ $transfer['to']->id }}">
                                    <button class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">
                                        یادآوری بفرست
                                    </button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @error('remind')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            @endif
        </div>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">وضعیت همه</h2>

            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($balances as $row)
                    <li class="flex flex-wrap items-baseline gap-2 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ $row['user']->name }}</span>

                        @if ($row['net'] > 0)
                            <span class="tabular text-emerald-700">
                                {{ number_format($row['net']) }} طلبکار
                            </span>
                        @elseif ($row['net'] < 0)
                            <span class="tabular text-amber-700">
                                {{ number_format(abs($row['net'])) }} بدهکار
                            </span>
                        @else
                            <span class="text-slate-400">صاف</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">خرج‌های اخیر</h2>

            @if ($expenses->isEmpty())
                <p class="mt-3 text-sm text-slate-500">
                    هنوز خرجی ثبت نشده. اولین شام یا سفر را بنویسید.
                </p>
            @else
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($expenses as $expense)
                        <li class="py-2.5 text-sm">
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span class="min-w-0 flex-1 truncate font-medium">{{ $expense->title }}</span>
                                <span class="tabular font-medium">{{ number_format($expense->amount) }}</span>
                            </div>
                            <p class="tabular mt-0.5 text-xs text-slate-500">
                                {{ $expense->payer->name }} پرداخت کرد ·
                                بین {{ $expense->shares->count() }} نفر ·
                                {{ JalaliDate::format(CarbonImmutable::parse($expense->spent_on)) }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($settlements->isNotEmpty())
            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-medium">پرداخت‌های ثبت‌شده</h2>

                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($settlements as $settlement)
                        <li class="tabular flex flex-wrap items-baseline gap-2 py-2">
                            <span>{{ $settlement->from->name }} ← {{ $settlement->to->name }}</span>
                            <span class="ms-auto">{{ number_format($settlement->amount) }}</span>
                            <span class="text-xs text-slate-400">
                                {{ JalaliDate::format(CarbonImmutable::parse($settlement->settled_on)) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <aside>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">ثبت خرج مشترک</h2>

            <form method="POST" action="{{ route('settlements.expenses.store') }}" class="mt-3 space-y-3">
                @csrf

                <div>
                    <label for="title" class="block text-sm">بابت چه چیزی</label>
                    <input id="title" name="title" required value="{{ old('title') }}"
                           placeholder="شام رستوران"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="amount" class="block text-sm">مبلغ <span class="text-xs text-slate-400">(ریال)</span></label>
                    <input id="amount" name="amount" required inputmode="numeric" dir="ltr" value="{{ old('amount') }}"
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    @error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="payer_id" class="block text-sm">چه کسی پرداخت کرد</label>
                    <select id="payer_id" name="payer_id"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                        @foreach ($members as $member)
                            <option value="{{ $member->id }}" @selected(old('payer_id', $me) == $member->id)>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <fieldset>
                    <legend class="block text-sm">بین چه کسانی تقسیم شود</legend>
                    <p class="mt-1 text-xs text-slate-400">
                        مساوی تقسیم می‌شود و باقی‌مانده‌ی ریالی هم گم نمی‌شود.
                    </p>

                    <div class="mt-2 space-y-1">
                        @foreach ($members as $member)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="participants[]" value="{{ $member->id }}" checked>
                                <span>{{ $member->name }}</span>
                            </label>
                        @endforeach
                    </div>

                    @error('participants')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </fieldset>

                <div>
                    <label for="spent_date" class="block text-sm">تاریخ</label>
                    <input id="spent_date" name="spent_date" required dir="ltr"
                           value="{{ old('spent_date', JalaliDate::format(CarbonImmutable::now())) }}"
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    @error('spent_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <button type="submit"
                        class="w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    ثبت خرج
                </button>
            </form>
        </div>

        @error('amount')
            <p class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
        @enderror
    </aside>
</div>

@endsection
