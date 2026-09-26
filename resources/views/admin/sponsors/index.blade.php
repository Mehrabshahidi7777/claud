@extends('layouts.admin')

@section('title', 'اسپانسرها')

@section('content')

<div class="mb-4 flex flex-wrap items-center gap-3">
    <div>
        <h1 class="text-xl font-bold">اسپانسرهای پیگیر</h1>
        <p class="mt-1 text-sm text-slate-500">
            کسانی که هزینه‌ی پیگیر را می‌دهند تا برای همه رایگان بماند. در صفحه‌ی ورود، داشبورد و صفحه‌ی «اسپانسرها» دیده می‌شوند.
        </p>
    </div>
    <a href="{{ route('admin.sponsors.create') }}"
       class="ms-auto rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
        اسپانسر تازه
    </a>
</div>

@if ($sponsors->isEmpty())
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">
        هنوز اسپانسری ثبت نشده. تا وقتی اسپانسری نباشد، بخش اسپانسرها در سامانه نمایش داده نمی‌شود.
    </div>
@else
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="border-b border-slate-200 text-xs text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">اسپانسر</th>
                    <th class="px-4 py-3 text-start font-medium">سایت</th>
                    <th class="px-4 py-3 text-start font-medium">کلیک</th>
                    <th class="px-4 py-3 text-start font-medium">ترتیب</th>
                    <th class="px-4 py-3 text-start font-medium">وضعیت</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($sponsors as $sponsor)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                @if ($sponsor->logoUrl())
                                    <img src="{{ $sponsor->logoUrl() }}" alt="" class="size-10 rounded-lg border border-slate-100 object-contain">
                                @endif
                                <div class="min-w-0">
                                    <p class="font-medium">{{ $sponsor->name }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ $sponsor->description }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 font-latin text-xs text-slate-500" dir="ltr">{{ $sponsor->website_url ?: '—' }}</td>
                        <td class="tabular px-4 py-3">{{ number_format($sponsor->clicks) }}</td>
                        <td class="tabular px-4 py-3">{{ $sponsor->sort_order }}</td>
                        <td class="px-4 py-3 text-xs">
                            @if ($sponsor->is_active)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800">نمایش داده می‌شود</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">پنهان</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('admin.sponsors.edit', $sponsor) }}" class="text-sm text-brand-700 hover:underline">ویرایش</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
