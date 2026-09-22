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
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
            <a href="{{ route('tasks.index') }}" class="text-lg font-bold text-slate-900">
                سامانه پیگیری
            </a>

            @isset($workspace)
                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600">
                    {{ $workspace->name }}
                </span>
            @endisset

            <nav class="flex items-center gap-1 text-sm">
                @php $current = request()->route()?->getName(); @endphp

                <a href="{{ route('tasks.index') }}"
                   class="rounded-lg px-3 py-2 {{ $current === 'tasks.index' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    تسک‌ها
                </a>
                <a href="{{ route('reports.index') }}"
                   class="rounded-lg px-3 py-2 {{ $current === 'reports.index' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    گزارش
                </a>
                <a href="{{ route('reports.weekly.index') }}"
                   class="rounded-lg px-3 py-2 {{ str_starts_with((string) $current, 'reports.weekly') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    هفتگی
                </a>
                <a href="{{ route('billing.index') }}"
                   class="rounded-lg px-3 py-2 {{ str_starts_with((string) $current, 'billing') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    صورتحساب
                </a>
                <a href="{{ route('members.index') }}"
                   class="rounded-lg px-3 py-2 {{ $current === 'members.index' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    اعضا
                </a>
            </nav>

            <form method="POST" action="{{ route('logout') }}" class="ms-auto">
                @csrf
                <button type="submit" class="text-sm text-slate-500 hover:text-slate-900">خروج</button>
            </form>
        </div>
    </header>
@endauth

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
