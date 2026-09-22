<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'سامانه پیگیری')</title>

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
