@extends('layouts.admin')

@section('title', $sponsor->exists ? $sponsor->name : 'اسپانسر تازه')

@section('content')

<a href="{{ route('admin.sponsors.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← همه‌ی اسپانسرها</a>

<div class="mt-3 grid gap-4 lg:grid-cols-[1fr_20rem]">
    <form method="POST" enctype="multipart/form-data"
          action="{{ $sponsor->exists ? route('admin.sponsors.update', $sponsor) : route('admin.sponsors.store') }}"
          class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
        @csrf
        @if ($sponsor->exists) @method('PUT') @endif

        <h1 class="text-lg font-bold">{{ $sponsor->exists ? 'ویرایش اسپانسر' : 'اسپانسر تازه' }}</h1>

        <div>
            <label for="name" class="block text-sm font-medium">نام</label>
            <input id="name" name="name" value="{{ old('name', $sponsor->name) }}" required maxlength="100"
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="description" class="block text-sm font-medium">توضیح کوتاه <span class="text-slate-400">(اختیاری)</span></label>
            <textarea id="description" name="description" rows="3" maxlength="400"
                      class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">{{ old('description', $sponsor->description) }}</textarea>
            <p class="mt-1 text-xs text-slate-400">یکی دو جمله درباره‌ی کارشان. حداکثر ۴۰۰ حرف.</p>
            @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="website_url" class="block text-sm font-medium">آدرس سایت <span class="text-slate-400">(اختیاری)</span></label>
            <input id="website_url" name="website_url" value="{{ old('website_url', $sponsor->website_url) }}" dir="ltr" placeholder="https://..."
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 font-latin text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
            @error('website_url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="logo" class="block text-sm font-medium">لوگو {{ $sponsor->exists ? '(برای عوض کردن)' : '' }}</label>
            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" {{ $sponsor->exists ? '' : 'required' }}
                   class="mt-1 block w-full text-sm file:me-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm">
            <p class="mt-1 text-xs text-slate-400">PNG، JPG یا WebP، حداکثر ۱ مگابایت. مربعی بهتر دیده می‌شود.</p>
            @error('logo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label for="sort_order" class="block text-sm font-medium">ترتیب نمایش</label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="1000" value="{{ old('sort_order', $sponsor->sort_order ?? 0) }}"
                       class="tabular mt-1 w-24 rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-400">عدد کمتر، جلوتر.</p>
            </div>

            <label class="flex items-center gap-2 pb-6 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $sponsor->is_active))>
                نمایش داده شود
            </label>
        </div>

        <button class="w-full rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-800">
            ذخیره
        </button>
    </form>

    @if ($sponsor->exists)
        <aside class="space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 text-center">
                @if ($sponsor->logoUrl())
                    <img src="{{ $sponsor->logoUrl() }}" alt="" class="mx-auto size-24 object-contain">
                @endif
                <p class="mt-3 font-medium">{{ $sponsor->name }}</p>
                <p class="tabular mt-1 text-sm text-slate-500">{{ number_format($sponsor->clicks) }} کلیک تا امروز</p>
            </div>

            <form method="POST" action="{{ route('admin.sponsors.destroy', $sponsor) }}"
                  data-confirm="«{{ $sponsor->name }}» حذف شود؟ اگر فقط موقتاً نمی‌خواهید دیده شود، تیک «نمایش داده شود» را بردارید.">
                @csrf
                @method('DELETE')
                <button class="w-full rounded-xl border border-red-200 px-4 py-2 text-sm text-red-700 hover:bg-red-50">حذف اسپانسر</button>
            </form>
        </aside>
    @endif
</div>

@endsection
