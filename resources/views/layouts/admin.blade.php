<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'مدیریت کل') · {{ config('brand.name') }}</title>
    <link rel="icon" href="/icons/logo.svg" type="image/svg+xml">
    <link rel="icon" href="/icons/icon-192.png" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">

{{-- A different header colour from the customer app on purpose: the person
     who can see every company should never mistake this for their own. --}}
<header class="bg-slate-900 text-white">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 px-4 py-3">
        <a href="{{ route('admin.dashboard') }}" class="flex shrink-0 items-center gap-2 text-lg font-bold">
            <img src="/icons/logo.svg" alt="" class="size-8" width="32" height="32">
            {{ config('brand.name') }}
            <span class="ms-1 rounded-md bg-amber-400 px-2 py-0.5 text-xs font-medium text-slate-900">مدیریت کل</span>
        </a>

        @php
            $current = request()->route()?->getName();
            $tab = fn (bool $on) => 'shrink-0 whitespace-nowrap rounded-lg px-3 py-2 text-sm '
                .($on ? 'bg-white text-slate-900' : 'text-slate-300 hover:bg-slate-800 hover:text-white');
        @endphp

        <nav class="-mx-1 flex min-w-0 flex-1 items-center gap-1 overflow-x-auto px-1">
            <a href="{{ route('admin.dashboard') }}" class="{{ $tab($current === 'admin.dashboard') }}">نمای کلی</a>
            <a href="{{ route('admin.workspaces.index') }}" class="{{ $tab(str_starts_with((string) $current, 'admin.workspaces')) }}">مشتری‌ها</a>
            @if (config('payment.enabled'))
                <a href="{{ route('admin.payments.index') }}" class="{{ $tab($current === 'admin.payments.index') }}">پرداخت‌ها</a>
            @endif
            <a href="{{ route('admin.sms.index') }}" class="{{ $tab($current === 'admin.sms.index') }}">پیامک‌ها</a>
            <a href="{{ route('admin.sponsors.index') }}" class="{{ $tab(str_starts_with((string) $current, 'admin.sponsors')) }}">اسپانسرها</a>
        </nav>

        @if (auth()->user()->workspaces()->exists())
            <a href="{{ route('dashboard') }}" class="shrink-0 text-sm text-slate-300 hover:text-white">سامانه‌ی خودم</a>
        @else
            {{-- The owner can open a workspace of their own to see پیگیر the
                 way a customer does. --}}
            <a href="{{ route('onboarding') }}" class="shrink-0 text-sm text-slate-300 hover:text-white">ساختن فضای کاری خودم</a>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="shrink-0">
            @csrf
            <button type="submit" class="text-sm text-slate-300 hover:text-white">خروج</button>
        </form>
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-6">
    @if (session('status'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first() }}
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
