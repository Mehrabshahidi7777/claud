@extends('layouts.app')

@section('title', 'بخش‌ها و دسترسی‌ها')

@section('content')

<div class="mx-auto max-w-5xl">

    <h1 class="mb-1 text-lg font-bold">بخش‌ها و دسترسی‌ها</h1>
    <p class="mb-5 text-sm text-slate-500">
        بخش تعیین می‌کند کسی <b>کجا</b> می‌نشیند، نقش تعیین می‌کند <b>چه کاری</b>
        می‌تواند بکند. این دو عمداً از هم جدا هستند.
    </p>

    <div class="grid gap-6 lg:grid-cols-[1fr_21rem]">

        <div class="min-w-0">
            @if ($departments->isEmpty())
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                    <p class="text-slate-600">هنوز بخشی تعریف نشده.</p>
                    <p class="mt-2 text-xs text-slate-400">
                        شرکت کوچک لازم نیست بخش داشته باشد — ولی وقتی دارد،
                        اگر کاری عقب بیفتد، خبرش به سرپرست همان بخش می‌رسد، نه به مدیرعامل.
                    </p>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($departments as $department)
                        @php
                            $people = $headcount[$department->id] ?? 0;
                            $open = $openTasks[$department->id] ?? 0;
                        @endphp

                        <div class="rounded-2xl border bg-white p-4 {{ $department->is_active ? 'border-slate-200' : 'border-slate-200 opacity-60' }}">
                            <form method="POST" action="{{ route('departments.update', $department) }}"
                                  class="flex flex-wrap items-end gap-2">
                                @csrf
                                @method('PATCH')

                                <div class="min-w-40 flex-1">
                                    <label class="block text-xs text-slate-500" for="name-{{ $department->id }}">
                                        {{ $department->kind->label() }}
                                    </label>
                                    <input id="name-{{ $department->id }}" name="name" value="{{ $department->name }}" required
                                           class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                                </div>

                                <div class="min-w-40 flex-1">
                                    <label class="block text-xs text-slate-500" for="lead-{{ $department->id }}">سرپرست</label>
                                    <select id="lead-{{ $department->id }}" name="lead_id"
                                            class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                                        <option value="">— ندارد —</option>
                                        @foreach ($members as $member)
                                            <option value="{{ $member->id }}" @selected($department->lead_id === $member->id)>
                                                {{ $member->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <button class="rounded-lg bg-brand-700 px-3 py-1.5 text-sm text-white hover:bg-brand-800">
                                    ذخیره
                                </button>
                            </form>

                            <div class="tabular mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                <span>{{ $people }} نفر</span>
                                <span>{{ $open }} کار باز</span>

                                @if ($people === 0 && $department->is_active)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">
                                        کسی در این بخش نیست
                                    </span>
                                @endif

                                @if ($department->lead_id === null && $department->is_active)
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5">
                                        بدون سرپرست — خبر کارهای عقب‌افتاده به مالک می‌رسد
                                    </span>
                                @endif

                                <form method="POST" action="{{ route('departments.toggle', $department) }}" class="ms-auto">
                                    @csrf
                                    <button class="text-xs text-slate-400 hover:text-slate-900">
                                        {{ $department->is_active ? 'بایگانی' : 'فعال‌سازی' }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- The matrix, written out rather than described. A customer
                 deciding who to trust with what needs to see it, not read a
                 paragraph about it. --}}
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-medium">نقش‌ها و دسترسی‌ها</h2>
                <p class="mt-1 text-xs text-slate-500">
                    حسابدار پول را می‌بیند و پرونده‌ی پرسنلی را نه؛ منابع انسانی
                    برعکس. ریختن هر دو در «مدیر» همان چیزی است که باعث می‌شود
                    شرکت قراردادهای واقعی‌اش را وارد نکند.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-150 text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-xs text-slate-500">
                                <th class="py-2 text-start font-medium">دسترسی</th>
                                @foreach ($roles as $role)
                                    <th class="px-1 py-2 text-center font-medium whitespace-nowrap">{{ $role->label() }}</th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            @foreach ($permissions as $group => $items)
                                <tr class="bg-slate-50/60">
                                    <td colspan="{{ count($roles) + 1 }}" class="py-1.5 text-xs font-medium text-slate-500">
                                        {{ $group }}
                                    </td>
                                </tr>

                                @foreach ($items as $permission)
                                    <tr>
                                        <td class="py-2 pe-3">{{ $permission->label() }}</td>
                                        @foreach ($roles as $role)
                                            <td class="px-1 py-2 text-center">
                                                @if ($role->can($permission))
                                                    <span class="text-emerald-600" aria-label="دارد">✓</span>
                                                @else
                                                    <span class="text-slate-300" aria-label="ندارد">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="mt-4 space-y-1.5 text-xs text-slate-500">
                    @foreach ($roles as $role)
                        <div class="flex flex-wrap gap-2">
                            <dt class="font-medium text-slate-700">{{ $role->label() }}</dt>
                            <dd>{{ $role->description() }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        <aside>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <h2 class="font-medium">بخش جدید</h2>

                <form method="POST" action="{{ route('departments.store') }}" class="mt-3 space-y-3">
                    @csrf

                    <div>
                        <label for="kind" class="block text-sm">نوع</label>
                        <select id="kind" name="kind"
                                class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                            @foreach ($kinds as $kind)
                                <option value="{{ $kind->value }}" @selected(old('kind') === $kind->value)>
                                    {{ $kind->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="new-name" class="block text-sm">
                            نام دلخواه <span class="text-xs text-slate-400">(اختیاری)</span>
                        </label>
                        <input id="new-name" name="name" value="{{ old('name') }}"
                               placeholder="اگر خالی بماند، همان نام نوع"
                               class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="new-lead" class="block text-sm">سرپرست</label>
                        <select id="new-lead" name="lead_id"
                                class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                            <option value="">— بعداً —</option>
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}" @selected(old('lead_id') == $member->id)>
                                    {{ $member->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">
                            خبر کار عقب‌افتاده‌ی این بخش به سرپرستش می‌رسد، نه به مدیرعامل.
                        </p>
                    </div>

                    <button type="submit"
                            class="w-full rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
                        افزودن بخش
                    </button>
                </form>
            </div>

            <a href="{{ route('members.index') }}"
               class="mt-3 block rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600 hover:border-slate-400">
                برای اینکه هر عضو در کدام بخش و با چه نقشی باشد، به صفحه‌ی
                <span class="font-medium text-slate-900">اعضا</span> بروید.
            </a>
        </aside>
    </div>
</div>

@endsection
