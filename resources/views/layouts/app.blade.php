<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'سامانه پیگیری')</title>

    {{-- Installable on a technician's phone. The field worker is the one who
         needs it on a home screen — they open it in a plant room with one bar
         of signal, not at a desk. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="پیگیری">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="icon" href="/icons/icon-192.png" type="image/png">

    {{-- Vazirmatn renders Persian correctly at small sizes, which the default
         system stack does not. Loaded from Google Fonts with a swap so the
         page is never blank while it arrives. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

@auth
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
            <a href="{{ route('tasks.index') }}" class="shrink-0 text-lg font-bold text-slate-900">
                سامانه پیگیری
            </a>

            @isset($workspace)
                <span class="hidden shrink-0 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600 sm:inline">
                    {{ $workspace->name }}
                </span>
            @endisset

            {{-- Scrolls sideways instead of wrapping. Eight links stacked over
                 three rows push the page content off a phone screen, and the
                 field worker's phone is the device this has to survive. --}}
            <nav class="-mx-1 flex min-w-0 flex-1 items-center gap-1 overflow-x-auto px-1 text-sm
                        [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @php $current = request()->route()?->getName(); @endphp

                <a href="{{ route('tasks.index') }}"
                   class="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 {{ $current === 'tasks.index' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    تسک‌ها
                </a>
                <a href="{{ route('meetings.index') }}"
                   class="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 {{ str_starts_with((string) $current, 'meetings') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    جلسات
                </a>
                <a href="{{ route('approvals.index') }}"
                   class="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 {{ str_starts_with((string) $current, 'approvals') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    درخواست‌ها
                </a>
                <a href="{{ route('reports.index') }}"
                   class="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 {{ $current === 'reports.index' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    گزارش
                </a>
                <a href="{{ route('reports.weekly.index') }}"
                   class="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 {{ str_starts_with((string) $current, 'reports.weekly') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    هفتگی
                </a>
                <a href="{{ route('billing.index') }}"
                   class="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 {{ str_starts_with((string) $current, 'billing') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    صورتحساب
                </a>
                <a href="{{ route('members.index') }}"
                   class="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 {{ $current === 'members.index' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    اعضا
                </a>

                @php
                    // The count the ladder's free rungs produce. Cached for a
                    // minute so a header rendered on every page does not cost
                    // a query on every page.
                    $unread = cache()->remember(
                        'unread-notifications:'.auth()->id(),
                        now()->addMinute(),
                        fn () => auth()->user()->unreadNotifications()->count(),
                    );
                @endphp

                <a href="{{ route('notifications.index') }}"
                   class="relative shrink-0 whitespace-nowrap rounded-lg px-3 py-2 {{ $current === 'notifications.index' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    اعلان‌ها
                    @if ($unread > 0)
                        <span class="tabular absolute -top-1 -start-1 rounded-full bg-red-600 px-1.5 text-[11px] text-white">
                            {{ $unread > 9 ? '۹+' : $unread }}
                        </span>
                    @endif
                </a>
            </nav>

            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                @csrf
                <button type="submit" class="text-sm text-slate-500 hover:text-slate-900">خروج</button>
            </form>
        </div>
    </header>
@endauth

@if (! empty($subscriptionLapsed))
    {{-- Not a one-shot flash. Someone bounced from a save needs to know why on
         whichever page they land on next, and the state persists until they
         pay — so the banner does too. --}}
    <div class="border-b border-amber-200 bg-amber-50 px-4 py-3">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 text-sm text-amber-900">
            <span>
                اشتراک شما تمام شده است. همه‌چیز قابل مشاهده است، ولی تا تمدید
                امکان ثبت تغییر جدید وجود ندارد.
            </span>

            <a href="{{ route('billing.index') }}"
               class="ms-auto rounded-lg bg-amber-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-800">
                تمدید اشتراک
            </a>
        </div>
    </div>
@endif

@auth
    {{-- Hidden until the browser says the app is installable, and dismissed
         for good once. A technician who never installs it is still reachable
         by SMS, so this is an offer and never a wall. --}}
    <div id="install-banner" class="hidden border-b border-slate-200 bg-slate-900 px-4 py-3 text-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 text-sm">
            <span>این سامانه را روی گوشی نصب کنید تا سریع‌تر به کارهایتان برسید.</span>

            <button data-install type="button"
                    class="ms-auto rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-slate-900">
                نصب
            </button>
            <button data-dismiss type="button" class="text-sm text-slate-300 hover:text-white">
                بعداً
            </button>
        </div>
    </div>
@endauth

<main class="mx-auto max-w-6xl px-4 py-6">
    @if (session('status'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
