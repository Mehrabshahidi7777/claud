@extends('layouts.app')

@section('title', 'اعضا')

@section('content')

<div class="grid gap-6 lg:grid-cols-[1fr_22rem]">

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <h1 class="font-medium">اعضای {{ $workspace->name }}</h1>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-500">
                    <tr class="border-b border-slate-200">
                        <th class="py-2 text-start font-medium">نام</th>
                        <th class="py-2 text-start font-medium">شماره</th>
                        <th class="py-2 text-start font-medium">نقش</th>
                        <th class="py-2 text-start font-medium">مدیر مستقیم</th>
                        <th class="py-2 text-start font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($members as $member)
                        @php $pivot = $member->pivot; @endphp

                        <tr>
                            <td class="py-2">
                                {{ $member->name ?: '— بی‌نام —' }}

                                @unless ($member->hasVerifiedPhone())
                                    {{-- Not a problem: they are assignable anyway. This is
                                         the differentiator, so it is labelled rather than
                                         hidden. --}}
                                    <span class="ms-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                        هنوز وارد نشده
                                    </span>
                                @endunless

                                @if ($member->hasOptedOutOfSms())
                                    <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                                        پیامک قطع
                                    </span>
                                @endif

                                @if ($pivot->away_until && \Carbon\Carbon::parse($pivot->away_until)->isFuture())
                                    <span class="ms-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-800">
                                        مرخصی
                                    </span>
                                @endif
                            </td>

                            <td class="tabular py-2 text-slate-500" dir="ltr">{{ $member->localPhone() }}</td>

                            <td class="py-2">
                                <form method="POST" action="{{ route('members.update', $member) }}"
                                      class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    @method('PATCH')

                                    <select name="role" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->value }}" @selected($pivot->role === $role->value)>
                                                {{ $role->label() }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @if ($departments->isNotEmpty())
                                        <select name="department_id" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">
                                            <option value="">— بدون بخش —</option>
                                            @foreach ($departments as $department)
                                                <option value="{{ $department->id }}"
                                                        @selected((int) $pivot->department_id === $department->id)>
                                                    {{ $department->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif

                                    <select name="manager_id" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">
                                        <option value="">— مالک فضای کاری —</option>
                                        @foreach ($members as $candidate)
                                            @continue($candidate->id === $member->id)
                                            <option value="{{ $candidate->id }}"
                                                    @selected((int) $pivot->manager_id === $candidate->id)>
                                                {{ $candidate->name }}
                                            </option>
                                        @endforeach
                                    </select>

                                    <button class="rounded-lg bg-slate-900 px-2 py-1 text-xs text-white">
                                        ذخیره
                                    </button>
                                </form>
                            </td>

                            <td class="py-2 text-xs text-slate-500">
                                @php
                                    $manager = $members->firstWhere('id', (int) $pivot->manager_id);
                                @endphp
                                {{ $manager?->name ?? 'مالک' }}
                            </td>

                            <td class="py-2">
                                @if ($member->hasOptedOutOfSms())
                                    <form method="POST" action="{{ route('members.resume-sms', $member) }}">
                                        @csrf
                                        <button class="text-xs text-sky-700 hover:underline">
                                            فعال‌سازی پیامک
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <aside>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="font-medium">افزودن عضو</h2>
            <p class="mt-1 text-xs text-slate-500">
                فقط نام و شماره. از همان لحظه قابل اساین شدن است، حتی اگر هرگز وارد سامانه نشود.
            </p>

            <form method="POST" action="{{ route('members.store') }}" class="mt-3 space-y-3">
                @csrf

                <div>
                    <label for="member-name" class="block text-sm">نام</label>
                    <input id="member-name" name="name" value="{{ old('name') }}" required
                           class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="member-phone" class="block text-sm">شماره موبایل</label>
                    <input id="member-phone" name="phone" value="{{ old('phone') }}" required
                           dir="ltr" placeholder="09121234567" inputmode="tel"
                           class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="member-role" class="block text-sm">نقش</label>
                    <select id="member-role" name="role"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(old('role', 'member') === $role->value)>
                                {{ $role->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('role')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

                    {{-- Spelled out beside the picker rather than left to a
                         help page: choosing a role is choosing what somebody
                         can see, and nobody reads the help page. --}}
                    <p class="mt-1 text-xs leading-6 text-slate-400">
                        @foreach ($roles as $role)
                            <span class="block"><b class="text-slate-600">{{ $role->label() }}</b> — {{ $role->description() }}</span>
                        @endforeach
                    </p>
                </div>

                @if ($departments->isNotEmpty())
                    <div>
                        <label for="member-department" class="block text-sm">بخش</label>
                        <select id="member-department" name="department_id"
                                class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— بدون بخش —</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>
                                    {{ $department->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label for="member-manager" class="block text-sm">مدیر مستقیم</label>
                    <select id="member-manager" name="manager_id"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— مالک فضای کاری —</option>
                        @foreach ($members as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">
                        تشدید تسک‌های عقب‌افتاده به این نفر می‌رود.
                    </p>
                </div>

                <button type="submit"
                        class="w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    افزودن
                </button>
            </form>
        </div>
    </aside>
</div>

@endsection
