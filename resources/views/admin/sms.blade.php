@extends('layouts.admin')

@section('title', 'پیامک‌ها')

@section('content')

@php
    use App\Support\JalaliDate;
    use App\Support\PhoneNumber;
    use Carbon\CarbonImmutable;
@endphp

<form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-2xl border border-slate-200 bg-white p-4">
    <div class="min-w-48 flex-1">
        <label for="phone" class="block text-xs text-slate-500">شماره‌ی گیرنده</label>
        <input id="phone" name="phone" value="{{ $filters['phone'] ?? '' }}" dir="ltr" placeholder="0912…"
               class="tabular mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div>
        <label for="status" class="block text-xs text-slate-500">وضعیت</label>
        <select id="status" name="status" class="mt-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <option value="">همه</option>
            @foreach (['sent' => 'رفته', 'failed' => 'ناموفق', 'queued' => 'در صف'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <button class="rounded-xl bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">جستجو</button>
</form>

<p class="mb-3 text-xs text-slate-500">پیامک‌های موتور پیگیری. کد ورود اینجا ثبت نمی‌شود.</p>

@if ($messages->isEmpty())
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">پیامکی پیدا نشد.</div>
@else
    <div class="space-y-2">
        @foreach ($messages as $message)
            <div class="rounded-2xl border bg-white p-4 text-sm {{ $message->status === 'failed' ? 'border-red-200' : 'border-slate-200' }}">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="tabular font-medium" dir="ltr">{{ PhoneNumber::toLocal($message->phone) }}</span>
                    @if ($message->workspace)
                        <a href="{{ route('admin.workspaces.show', $message->workspace) }}" class="text-xs text-slate-500 hover:underline">{{ $message->workspace->name }}</a>
                    @endif
                    <span class="rounded-full px-2 py-0.5 text-xs {{ match ($message->status) { 'sent' => 'bg-emerald-50 text-emerald-700', 'failed' => 'bg-red-50 text-red-700', default => 'bg-slate-100 text-slate-600' } }}">
                        {{ match ($message->status) { 'sent' => 'رفته', 'failed' => 'ناموفق', default => 'در صف' } }}
                    </span>
                    <span class="tabular ms-auto text-xs text-slate-400">
                        {{ JalaliDate::format(CarbonImmutable::parse($message->created_at)) }} {{ $message->created_at->format('H:i') }}
                    </span>
                </div>
                @if ($message->rendered_preview)
                    <p class="mt-2 whitespace-pre-line text-slate-600">{{ $message->rendered_preview }}</p>
                @endif
                @if ($message->error)
                    <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700" dir="ltr">{{ \Illuminate\Support\Str::limit($message->error, 300) }}</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-4">{{ $messages->links() }}</div>
@endif

@endsection
