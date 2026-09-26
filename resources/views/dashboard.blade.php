@extends('layouts.app')

@section('title', 'خانه')

@section('content')

@php
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;

    $mine = $cards['mine'];
    $attention = $cards['attention'];
@endphp

<div class="mx-auto max-w-6xl">

    <div class="mb-5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h1 class="text-lg font-bold">{{ $workspace->name }}</h1>
        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">
            {{ $workspace->type->label() }}
        </span>
        <span class="text-sm text-slate-500">{{ $workspace->type->tagline() }}</span>
    </div>

    @if ($gettingStarted)
        @php $doneCount = collect($gettingStarted)->where('done', true)->count(); @endphp

        {{-- The first-days checklist. It disappears by itself once every
             step is done, and can be hidden sooner. --}}
        <section class="mb-4 rounded-2xl border border-brand-100 bg-brand-50/60 p-5">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 class="font-bold">شروع کار با {{ config('brand.name') }}</h2>
                <span class="tabular text-sm text-slate-600">{{ $doneCount }} از {{ count($gettingStarted) }}</span>

                <form method="POST" action="{{ route('getting-started.dismiss') }}" class="ms-auto">
                    @csrf
                    <button class="text-sm text-slate-500 hover:text-slate-900">بستن</button>
                </form>
            </div>

            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white">
                <div class="h-full rounded-full bg-brand-600" style="width: {{ round($doneCount / count($gettingStarted) * 100) }}%"></div>
            </div>

            <ol class="mt-4 grid gap-2 {{ count($gettingStarted) === 3 ? 'md:grid-cols-3' : 'md:grid-cols-2' }}">
                @foreach ($gettingStarted as $step)
                    <li>
                        <a href="{{ $step['url'] }}"
                           class="flex h-full gap-3 rounded-xl border bg-white p-3 transition
                                  {{ $step['done'] ? 'border-transparent opacity-60' : 'border-slate-200 hover:border-brand-300' }}">
                            @if ($step['done'])
                                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-emerald-500 text-white">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                    </svg>
                                </span>
                            @else
                                <span class="size-6 shrink-0 rounded-full border-2 border-slate-300"></span>
                            @endif

                            <span>
                                <span class="block text-sm font-medium {{ $step['done'] ? 'line-through' : '' }}">{{ $step['label'] }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ $step['hint'] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-3 [&>*]:min-w-0">

        {{-- The viewer's own work first. A manager sees their list and so
             does the technician who otherwise only ever gets texted. --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-2">
            <div class="flex flex-wrap items-baseline gap-2">
                <h2 class="font-medium">کارهای من</h2>

                @if ($mine['overdue'] > 0)
                    <span class="tabular rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">
                        {{ $mine['overdue'] }} عقب‌افتاده
                    </span>
                @endif

                @if ($mine['today'] > 0)
                    <span class="tabular rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                        {{ $mine['today'] }} امروز
                    </span>
                @endif

                <a href="{{ route('tasks.index', ['filter' => 'mine']) }}"
                   class="ms-auto text-sm text-slate-500 hover:text-slate-900">همه</a>
            </div>

            @if ($mine['tasks']->isEmpty())
                <p class="mt-3 text-sm text-slate-500">
                    چیزی روی دوش شما نیست. اگر کاری سپرده شود، خودش اینجا ظاهر می‌شود.
                </p>
            @else
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($mine['tasks'] as $task)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                            <a href="{{ route('tasks.show', $task) }}"
                               class="min-w-0 flex-1 truncate hover:underline">{{ $task->title }}</a>

                            @if ($task->due_at)
                                <span class="tabular text-xs {{ $task->isOverdue() ? 'font-medium text-red-600' : 'text-slate-500' }}">
                                    {{ JalaliDate::format(CarbonImmutable::parse($task->due_at)) }}
                                </span>
                            @else
                                <span class="text-xs text-slate-400">بدون مهلت</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- One honest number. Counted on what fell due this week, not on
             what was closed — closing an old task today must not push the
             rate above a hundred. --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-medium">هفت روز گذشته</h2>

            @if ($week['rate'] === null)
                <p class="mt-3 text-sm text-slate-500">
                    این هفته چیزی سررسید نشده که بشود شمرد.
                </p>
            @else
                <p class="tabular mt-2 text-3xl font-bold {{ $week['rate'] >= 70 ? 'text-emerald-700' : ($week['rate'] >= 40 ? 'text-amber-700' : 'text-red-700') }}">
                    {{ $week['rate'] }}<span class="text-lg font-normal text-slate-400">٪</span>
                </p>
                <p class="tabular mt-1 text-sm text-slate-500">
                    {{ $week['closed'] }} از {{ $week['due'] }} کار به‌موقع بسته شد
                </p>
            @endif

            @if ($workspace->has('reports'))
                <a href="{{ route('reports.index') }}"
                   class="mt-3 inline-block text-sm text-slate-500 hover:text-slate-900">گزارش کامل</a>
            @endif
        </section>

        {{-- What the engine will do next. This is the product stated as a
             list: things are going to happen without anyone doing them. --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-2">
            <h2 class="font-medium">قدم بعدی سامانه</h2>

            @if ($cards['nextMoves']->isEmpty())
                <p class="mt-3 text-sm text-slate-500">
                    فعلاً پیگیری‌ای در نوبت نیست. هر کاری که مهلت داشته باشد، پیگیری‌اش خودکار برنامه‌ریزی می‌شود.
                </p>
            @else
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($cards['nextMoves'] as $followUp)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                            <span class="size-1.5 shrink-0 rounded-full bg-amber-400"></span>
                            <a href="{{ route('tasks.show', $followUp->task) }}"
                               class="min-w-0 flex-1 truncate hover:underline">{{ $followUp->task->title }}</a>
                            <span class="text-xs text-slate-500">{{ $followUp->step->label() }}</span>
                            <span class="text-xs text-slate-400">{{ $followUp->recipient?->name }}</span>
                            <span class="tabular text-xs text-slate-400">
                                {{ $followUp->whenDue() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="rounded-2xl border bg-white p-5 {{ $attention['overdue']->isNotEmpty() ? 'border-red-200' : 'border-slate-200' }}">
            <h2 class="font-medium">نیاز به رسیدگی</h2>

            @if ($attention['overdue']->isEmpty() && $attention['unassigned'] === 0 && $attention['repeatedlyDeferred'] === 0)
                <p class="mt-3 text-sm text-emerald-700">چیزی عقب نیست.</p>
            @else
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach ($attention['overdue'] as $task)
                        <li class="flex flex-wrap items-baseline gap-2">
                            <a href="{{ route('tasks.show', $task) }}"
                               class="min-w-0 flex-1 truncate hover:underline">{{ $task->title }}</a>
                            <span class="tabular text-xs text-red-600">{{ $task->hoursOverdue() }} ساعت</span>
                        </li>
                    @endforeach
                </ul>

                <div class="tabular mt-3 space-y-1 text-xs text-slate-500">
                    @if ($attention['unassigned'] > 0)
                        <p>{{ $attention['unassigned'] }} کار بدون مسئول — پیگیری‌اش به ثبت‌کننده می‌رسد.</p>
                    @endif
                    @if ($attention['repeatedlyDeferred'] > 0)
                        <p>{{ $attention['repeatedlyDeferred'] }} کار چند بار پشت سر هم تأخیر خورده.</p>
                    @endif
                </div>
            @endif
        </section>

        @isset ($cards['money'])
            <section class="rounded-2xl border bg-white p-5 {{ $cards['money']['unchased'] > 0 ? 'border-amber-300' : 'border-slate-200' }}">
                <div class="flex items-baseline gap-2">
                    <h2 class="font-medium">پول</h2>
                    <a href="{{ route('finance.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">مالی</a>
                </div>

                <p class="tabular mt-2 text-xl font-bold">
                    {{ number_format($cards['money']['outstanding']) }}
                    <span class="text-xs font-normal text-slate-400">ریال وصول‌نشده</span>
                </p>

                @if ($cards['money']['overdue'] > 0)
                    <p class="tabular mt-1 text-sm text-amber-700">
                        {{ number_format($cards['money']['overdue']) }} از سررسید گذشته
                    </p>
                @endif

                @if ($cards['money']['unchased'] > 0)
                    <p class="tabular mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        {{ $cards['money']['unchased'] }} فقره معوق که هنوز کسی پیگیرش نیست.
                    </p>
                @endif
            </section>
        @endisset

        @isset ($cards['recurring'])
            <section class="rounded-2xl border bg-white p-5 {{ $cards['recurring']['overdue'] > 0 ? 'border-amber-300' : 'border-slate-200' }}">
                <div class="flex items-baseline gap-2">
                    <h2 class="font-medium">دوره‌ای</h2>
                    <a href="{{ route('recurring.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">همه</a>
                </div>

                @if ($cards['recurring']['valueAtRisk'] > 0)
                    <p class="tabular mt-2 text-sm text-red-700">
                        {{ number_format($cards['recurring']['valueAtRisk']) }} ریال روی زمین مانده
                    </p>
                @endif

                @if ($cards['recurring']['dueSoon']->isEmpty())
                    <p class="mt-3 text-sm text-slate-500">چیزی در نوبت نیست.</p>
                @else
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach ($cards['recurring']['dueSoon'] as $recurrence)
                            <li class="flex flex-wrap items-baseline gap-2">
                                <span class="min-w-0 flex-1 truncate">{{ $recurrence->title }}</span>
                                <span class="tabular text-xs {{ $recurrence->isOverdue() ? 'text-red-600' : 'text-slate-500' }}">
                                    {{ JalaliDate::format(CarbonImmutable::parse($recurrence->next_due_on)) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endisset

        @isset ($cards['contracts'])
            <section class="rounded-2xl border bg-white p-5 {{ $cards['contracts']['serious'] > 0 ? 'border-red-300' : 'border-slate-200' }}">
                <div class="flex items-baseline gap-2">
                    <h2 class="font-medium">قراردادها</h2>
                    <a href="{{ route('contracts.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">همه</a>
                </div>

                @if ($cards['contracts']['serious'] > 0)
                    <p class="tabular mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-900">
                        {{ $cards['contracts']['serious'] }} مورد منقضی که رها کردنش گران است.
                    </p>
                @endif

                @if ($cards['contracts']['expiring']->isEmpty())
                    <p class="mt-3 text-sm text-slate-500">چیزی نزدیک انقضا نیست.</p>
                @else
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach ($cards['contracts']['expiring'] as $contract)
                            <li class="flex flex-wrap items-baseline gap-2">
                                <a href="{{ route('contracts.show', $contract) }}"
                                   class="min-w-0 flex-1 truncate hover:underline">{{ $contract->title }}</a>
                                <span class="tabular text-xs {{ $contract->hasExpired() ? 'text-red-600' : 'text-slate-500' }}">
                                    @if ($contract->hasExpired())
                                        {{ abs($contract->daysToExpiry()) }} روز گذشته
                                    @else
                                        {{ $contract->daysToExpiry() }} روز مانده
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endisset

        @isset ($cards['settlements'])
            <section class="rounded-2xl border bg-white p-5 {{ $cards['settlements']['owed_by_me'] > 0 ? 'border-amber-300' : 'border-slate-200' }}">
                <div class="flex items-baseline gap-2">
                    <h2 class="font-medium">حساب‌وکتاب</h2>
                    <a href="{{ route('settlements.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">همه</a>
                </div>

                @if ($cards['settlements']['net'] === 0)
                    <p class="mt-2 text-sm text-emerald-700">حساب شما صاف است.</p>
                @elseif ($cards['settlements']['net'] > 0)
                    <p class="tabular mt-2 text-xl font-bold text-emerald-700">
                        {{ number_format($cards['settlements']['net']) }}
                        <span class="text-xs font-normal text-slate-400">ریال طلبکارید</span>
                    </p>
                @else
                    <p class="tabular mt-2 text-xl font-bold text-amber-700">
                        {{ number_format($cards['settlements']['owed_by_me']) }}
                        <span class="text-xs font-normal text-slate-400">ریال بدهکارید</span>
                    </p>
                @endif

                @if ($cards['settlements']['transfers'] > 0)
                    <p class="tabular mt-2 text-xs text-slate-500">
                        با {{ $cards['settlements']['transfers'] }} پرداخت، حساب همه صاف می‌شود.
                    </p>
                @endif
            </section>
        @endisset

        @isset ($cards['approvals'])
            @if ($cards['approvals']['waitingOnMe'] > 0 || $cards['approvals']['mine'] > 0)
                <section class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="flex items-baseline gap-2">
                        <h2 class="font-medium">درخواست‌ها</h2>
                        <a href="{{ route('approvals.index') }}" class="ms-auto text-sm text-slate-500 hover:text-slate-900">همه</a>
                    </div>

                    <div class="tabular mt-2 space-y-1 text-sm text-slate-600">
                        @if ($cards['approvals']['waitingOnMe'] > 0)
                            <p>{{ $cards['approvals']['waitingOnMe'] }} مورد منتظر تصمیم شما.</p>
                        @endif
                        @if ($cards['approvals']['mine'] > 0)
                            <p>{{ $cards['approvals']['mine'] }} درخواست شما در انتظار پاسخ.</p>
                        @endif
                    </div>
                </section>
            @endif
        @endisset
    </div>

    @php $sponsors = \App\Models\Sponsor::showcase(); @endphp

    @if ($sponsors->isNotEmpty())
        {{-- The people who keep پیگیر free. A quiet row at the foot of the
             page: thanked, never in the way of the work. --}}
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex items-baseline gap-2">
                <h2 class="text-sm font-medium text-slate-700">اسپانسرهای پیگیر</h2>
                <span class="text-xs text-slate-400">پیگیر به لطف این مجموعه‌ها رایگان است</span>
                <a href="{{ route('sponsors.index') }}" class="ms-auto text-xs text-slate-500 hover:text-slate-900">همه</a>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                @foreach ($sponsors->take(8) as $sponsor)
                    <a href="{{ $sponsor->visitUrl() ?? route('sponsors.index') }}" @if ($sponsor->visitUrl()) target="_blank" rel="sponsored noopener" @endif
                       title="{{ $sponsor->name }}"
                       class="flex items-center gap-2 rounded-xl border border-slate-100 px-2 py-1.5 text-sm text-slate-700 hover:border-brand-200">
                        @if ($sponsor->logoUrl())
                            <img src="{{ $sponsor->logoUrl() }}" alt="" loading="lazy" class="size-8 rounded-lg object-contain">
                        @endif
                        {{ $sponsor->name }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>

@endsection
