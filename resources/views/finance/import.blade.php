@extends('layouts.app')

@section('title', 'درون‌ریزی فاکتورها')

@section('content')

<div class="mx-auto max-w-2xl">

    <div class="mb-4 flex flex-wrap items-baseline gap-3">
        <h1 class="text-lg font-bold">درون‌ریزی فاکتورها</h1>
        <a href="{{ route('finance.receivables') }}" class="text-sm text-slate-500 hover:text-slate-900">
            ← مطالبات
        </a>
    </div>

    <p class="text-sm leading-8 text-slate-600">
        خروجی فاکتورهای فروش را از نرم‌افزار حسابداری‌تان بگیرید و همین‌جا
        بدهید. هر فاکتور تبدیل به یک مطالبه می‌شود و اگر سررسیدش بگذرد، خودش
        پیگیری می‌شود.
    </p>

    @if ($result)
        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p class="font-medium">درون‌ریزی انجام شد.</p>
            <ul class="tabular mt-2 space-y-0.5">
                <li>{{ $result['created'] }} فاکتور تازه ثبت شد</li>
                <li>{{ $result['updated'] }} فاکتور به‌روز شد</li>
                <li>{{ $result['settled'] }} فاکتور تسویه‌شده بسته شد</li>
                <li>{{ $result['unchanged'] }} فاکتور بدون تغییر بود</li>
            </ul>
        </div>
    @endif

    @if (! empty($skipped))
        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-medium">{{ count($skipped) }} ردیف خوانده نشد:</p>
            <ul class="tabular mt-2 space-y-0.5">
                @foreach (array_slice($skipped, 0, 10) as $row)
                    <li>ردیف {{ $row['row'] }} — {{ $row['reason'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('finance.import.store') }}" enctype="multipart/form-data"
          class="mt-4 space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
        @csrf

        <div>
            <label for="file" class="block text-sm font-medium">فایل CSV</label>
            <input id="file" name="file" type="file" accept=".csv,text/csv" required
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm file:me-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-white">
            @error('file')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="rounded-lg bg-slate-50 px-3 py-2 text-xs leading-7 text-slate-600">
            <p class="font-medium text-slate-900">در اکسل: ذخیره به‌صورت <span dir="ltr">CSV UTF-8</span></p>
            <p>ستون‌های لازم: <b>نام مشتری</b>، <b>مبلغ</b>، <b>تاریخ سررسید</b>.</p>
            <p>اختیاری: شماره فاکتور، شماره تماس، شرح، مبلغ دریافتی، تاریخ صدور.</p>
            <p>تاریخ شمسی و میلادی هر دو خوانده می‌شود.</p>
        </div>

        {{-- The rule that decides whether a second upload is safe, said before
             the upload rather than after it. --}}
        <div class="rounded-lg border border-slate-200 px-3 py-2 text-xs leading-7 text-slate-600">
            <p class="font-medium text-slate-900">درون‌ریزی دوباره‌ی همان فایل بی‌خطر است</p>
            <p>ردیف‌ها با <b>شماره فاکتور</b> تطبیق داده می‌شوند، پس تکراری ساخته نمی‌شود.</p>
            <p>اگر فایلتان شماره فاکتور ندارد، هر بار ردیف تازه ساخته می‌شود.</p>
            <p>مسئول وصول که خودتان تعیین کرده‌اید، با درون‌ریزی بعدی پاک نمی‌شود.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit"
                    class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800">
                درون‌ریزی
            </button>

            <a href="{{ route('finance.import.template') }}" class="text-sm text-slate-500 hover:text-slate-900">
                دریافت فایل نمونه
            </a>
        </div>
    </form>

    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 text-sm leading-8 text-slate-600">
        <h2 class="font-medium text-slate-900">اتصال مستقیم به سپیدار و هلو</h2>
        <p class="mt-1">
            هنوز ساخته نشده، و عمداً. اتصال مستقیم به هر نرم‌افزار حسابداری
            نسخه‌به‌نسخه فرق می‌کند و باید با لایسنس خودِ شما تنظیم شود. اگر
            برایتان لازم شد بگویید تا برای همان یکی ساخته شود.
        </p>
        <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs">
            یک قاعده در هر حالت ثابت است: <b>ما فقط از دفاتر شما می‌خوانیم و
            هرگز چیزی در آن نمی‌نویسیم.</b>
        </p>
    </div>
</div>

@endsection
