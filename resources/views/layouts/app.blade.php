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
    <meta name="theme-color" content="#0f172a">
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

@auth
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
            <a href="{{ route('dashboard') }}" class="shrink-0 text-lg font-bold text-slate-900">
                {{ config('brand.name') }}
            </a>

            @isset($workspace)
                @php
                    // Only worth a control when there is somewhere to go. Most
                    // people belong to exactly one and should see a label.
                    $otherWorkspaces = auth()->user()->workspaces()
                        ->where('workspaces.id', '!=', $workspace->id)
                        ->orderBy('name')
                        ->get();
                @endphp

                @if ($otherWorkspaces->isEmpty())
                    <span class="hidden shrink-0 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600 sm:inline">
                        {{ $workspace->name }}
                    </span>
                @else
                    <details class="relative hidden shrink-0 sm:block">
                        <summary class="cursor-pointer list-none rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600 hover:bg-slate-200">
                            {{ $workspace->name }}
                            <span class="text-xs text-slate-400">({{ $workspace->type->label() }})</span>
                        </summary>

                        <div class="absolute start-0 z-20 mt-1 w-56 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                            @foreach ($otherWorkspaces as $other)
                                <form method="POST" action="{{ route('workspaces.switch', $other->id) }}">
                                    @csrf
                                    <button class="w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-slate-100">
                                        {{ $other->name }}
                                        <span class="block text-xs text-slate-400">{{ $other->type->label() }}</span>
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </details>
                @endif
            @endisset

            {{-- Scrolls sideways instead of wrapping. Eight links stacked over
                 three rows push the page content off a phone screen, and the
                 field worker's phone is the device this has to survive. --}}
            {{-- Scrolls sideways instead of wrapping: on a phone, ten links
                 stacked three rows deep push the page itself off the screen,
                 and the field worker's phone is the device this must survive.

                 Which links exist at all comes from the workspace type, so a
                 household is never shown the receivables page it does not
                 have. The routes behind them answer 404 too — a hidden link
                 is still a URL somebody eventually types. --}}
            <nav class="-mx-1 flex min-w-0 flex-1 items-center gap-1 overflow-x-auto px-1 text-sm
                        [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @php
                    $current = request()->route()?->getName();

                    $tab = fn (bool $on) => 'shrink-0 whitespace-nowrap rounded-lg px-3 py-2 '
                        .($on ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100');

                    $starts = fn (string $prefix) => str_starts_with((string) $current, $prefix);
                @endphp

                <a href="{{ route('dashboard') }}" class="{{ $tab($current === 'dashboard') }}">خانه</a>

                <a href="{{ route('tasks.index') }}" class="{{ $tab($starts('tasks')) }}">تسک‌ها</a>

                @if ($workspace->has('recurring'))
                    <a href="{{ route('recurring.index') }}" class="{{ $tab($starts('recurring')) }}">دوره‌ای</a>
                @endif

                @if ($workspace->has('settlements'))
                    <a href="{{ route('settlements.index') }}" class="{{ $tab($starts('settlements')) }}">حساب‌وکتاب</a>
                @endif

                @if ($workspace->has('contracts') && $allowed(App\Enums\Permission::ViewContracts))
                    <a href="{{ route('contracts.index') }}" class="{{ $tab($starts('contracts')) }}">قراردادها</a>
                @endif

                @if ($workspace->has('meetings'))
                    <a href="{{ route('meetings.index') }}" class="{{ $tab($starts('meetings')) }}">جلسات</a>
                @endif

                @if ($workspace->has('approvals'))
                    <a href="{{ route('approvals.index') }}" class="{{ $tab($starts('approvals')) }}">درخواست‌ها</a>
                @endif

                {{-- Finance also turns on the role: what the company spends is
                     not something every member sees. --}}
                @if ($workspace->has('finance') && ($financeVisible ?? false))
                    <a href="{{ route('finance.index') }}" class="{{ $tab($starts('finance')) }}">مالی</a>
                @endif

                @if ($workspace->has('reports') && ($reportsVisible ?? false))
                    <a href="{{ route('reports.index') }}" class="{{ $tab($current === 'reports.index') }}">گزارش</a>
                    <a href="{{ route('reports.weekly.index') }}" class="{{ $tab($starts('reports.weekly')) }}">هفتگی</a>
                @endif

                @if ($workspace->has('departments') && $allowed(App\Enums\Permission::ManageDepartments))
                    <a href="{{ route('departments.index') }}" class="{{ $tab($starts('departments')) }}">بخش‌ها</a>
                @endif

                @if ($workspace->has('members') && $allowed(App\Enums\Permission::ManageMembers))
                    <a href="{{ route('members.index') }}" class="{{ $tab($current === 'members.index') }}">
                        {{ $workspace->type->memberWord() }}
                    </a>
                @endif

                @if ($workspace->has('billing') && $allowed(App\Enums\Permission::ManageBilling))
                    <a href="{{ route('billing.index') }}" class="{{ $tab($starts('billing')) }}">صورتحساب</a>
                @endif

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
                   class="relative {{ $tab($current === 'notifications.index') }}">
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
