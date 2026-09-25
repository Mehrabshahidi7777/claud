<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('brand.name'))</title>

    {{-- Installable on a technician's phone. The field worker is the one who
         needs it on a home screen — they open it in a plant room with one bar
         of signal, not at a desk. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#283f9f">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('brand.name') }}">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="icon" href="/icons/icon-192.png" type="image/png">

    {{-- Vazirmatn is bundled by Vite from resources/fonts (see app.css), so
         the page loads nothing from outside the server. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

@php
    $user = auth()->user();

    // No workspace yet (finishing sign-up, or the platform owner) means no
    // modules to link to; the page keeps only the name and sign-out.
    $hasNav = $user !== null && isset($workspace);

    if ($hasNav) {
        $otherWorkspaces = $user->workspaces()
            ->where('workspaces.id', '!=', $workspace->id)
            ->orderBy('name')
            ->get();

        // The count the ladder's free rungs produce. Cached for a minute so a
        // menu rendered on every page does not cost a query on every page.
        $unread = cache()->remember(
            'unread-notifications:'.$user->id,
            now()->addMinute(),
            fn () => $user->unreadNotifications()->count(),
        );
    }

    $mark = '<span class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">پ</span>';
@endphp

@auth
    @if ($hasNav)
        {{-- Desktop: the menu is a sidebar, so a company with every module
             switched on still reads as a list rather than a crammed row. --}}
        <aside class="fixed inset-y-0 start-0 z-30 hidden w-64 flex-col border-e border-slate-200 bg-white lg:flex">
            <div class="space-y-4 border-b border-slate-100 px-4 pt-5 pb-4">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 px-1">
                    {!! $mark !!}
                    <span class="text-lg font-bold text-slate-900">{{ config('brand.name') }}</span>
                </a>

                @include('layouts.partials.workspace-switcher')
            </div>

            <div class="flex-1 overflow-y-auto px-3 py-4">
                @include('layouts.partials.nav')
            </div>

            <div class="space-y-2 border-t border-slate-100 p-3">
                @if ($user->isPlatformAdmin())
                    <a href="{{ route('admin.dashboard') }}"
                       class="block rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100">
                        پنل مدیریت کل
                    </a>
                @endif

                <div class="flex items-center gap-2 px-1">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800">{{ $user->name ?: '—' }}</p>
                        <p class="tabular text-xs text-slate-500" dir="ltr">{{ $user->localPhone() }}</p>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-900">خروج</button>
                    </form>
                </div>
            </div>
        </aside>
    @endif

    {{-- Phones and tablets: a slim bar with the bell and a menu button. The
         field worker's phone is the device this has to survive. --}}
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur {{ $hasNav ? 'lg:hidden' : '' }}">
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
            <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2">
                {!! $mark !!}
                <span class="text-lg font-bold text-slate-900">{{ config('brand.name') }}</span>
            </a>

            @if ($hasNav)
                <span class="min-w-0 truncate text-sm text-slate-500">{{ $workspace->name }}</span>
            @endif

            <span class="flex-1"></span>

            @if ($user->isPlatformAdmin())
                <a href="{{ route('admin.dashboard') }}"
                   class="shrink-0 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100">
                    مدیریت کل
                </a>
            @endif

            @if ($hasNav)
                <a href="{{ route('notifications.index') }}" class="relative shrink-0 rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900" aria-label="اعلان‌ها">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                    </svg>
                    @if ($unread > 0)
                        <span class="tabular absolute top-0.5 end-0.5 min-w-4 rounded-full bg-red-500 px-1 text-center text-[10px] font-medium leading-4 text-white ring-2 ring-white">
                            {{ $unread > 9 ? '۹+' : $unread }}
                        </span>
                    @endif
                </a>

                <details class="group shrink-0">
                    <summary class="flex cursor-pointer list-none items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        </svg>
                        منو
                    </summary>

                    <div class="absolute inset-x-0 top-full max-h-[calc(100vh-4rem)] overflow-y-auto border-b border-slate-200 bg-white px-4 pt-3 pb-4 shadow-lg">
                        <div class="mx-auto max-w-md space-y-4">
                            @include('layouts.partials.workspace-switcher')
                            @include('layouts.partials.nav')

                            <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 pt-3">
                                @csrf
                                <button type="submit" class="w-full rounded-lg px-3 py-2 text-start text-sm text-slate-600 hover:bg-slate-100">خروج</button>
                            </form>
                        </div>
                    </div>
                </details>
            @else
                <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="rounded-lg px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-900">خروج</button>
                </form>
            @endif
        </div>
    </header>
@endauth

<div class="{{ $hasNav ? 'lg:ps-64' : '' }}">
    @if (! empty($subscriptionLapsed))
        {{-- Not a one-shot flash. Someone bounced from a save needs to know why
             on whichever page they land on next, and the state persists until
             they pay — so the banner does too. --}}
        <div class="border-b border-amber-200 bg-amber-50 px-4 py-3">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 text-sm text-amber-900">
                <span>
                    اشتراک شما تمام شده است. همه‌چیز قابل مشاهده است، ولی تا تمدید
                    امکان ثبت تغییر جدید وجود ندارد.
                </span>

                <a href="{{ route('billing.index') }}"
                   class="ms-auto rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700">
                    تمدید اشتراک
                </a>
            </div>
        </div>
    @endif

    @auth
        {{-- Hidden until the browser says the app is installable, and dismissed
             for good once. A technician who never installs it is still
             reachable by SMS, so this is an offer and never a wall. --}}
        <div id="install-banner" class="hidden border-b border-brand-100 bg-brand-50 px-4 py-3">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 text-sm text-brand-900">
                <span>این سامانه را روی گوشی نصب کنید تا سریع‌تر به کارهایتان برسید.</span>

                <button data-install type="button"
                        class="ms-auto rounded-lg bg-brand-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-800">
                    نصب
                </button>
                <button data-dismiss type="button" class="text-sm text-brand-700 hover:text-brand-900">
                    بعداً
                </button>
            </div>
        </div>
    @endauth

    <main class="mx-auto max-w-6xl px-4 py-6 lg:px-8">
        @if (session('status'))
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>
</div>

</body>
</html>
