@extends('layouts.admin')

@section('title', 'مشتری‌ها')

@section('content')

@php
    use App\Enums\WorkspaceType;
    use App\Support\JalaliDate;
    use Carbon\CarbonImmutable;
@endphp

<form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-2xl border border-slate-200 bg-white p-4">
    <div class="min-w-48 flex-1">
        <label for="q" class="block text-xs text-slate-500">نام شرکت یا شماره‌ی یکی از اعضا</label>
        <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="مثلاً تأسیسات یا 0912…"
               class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none">
    </div>

    <div>
        <label for="type" class="block text-xs text-slate-500">پلن</label>
        <select id="type" name="type" class="mt-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <option value="">همه</option>
            @foreach (WorkspaceType::cases() as $type)
                <option value="{{ $type->value }}" @selected(($filters['type'] ?? null) === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="status" class="block text-xs text-slate-500">اشتراک</label>
        <select id="status" name="status" class="mt-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <option value="">همه</option>
            @foreach (['active' => 'فعال', 'trialing' => 'آزمایشی', 'grace' => 'مهلت ارفاق', 'lapsed' => 'منقضی'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">جستجو</button>
</form>

@if ($workspaces->isEmpty())
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">
        مشتری‌ای با این مشخصات پیدا نشد.
    </div>
@else
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="border-b border-slate-200 text-xs text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">فضای کاری</th>
                    <th class="px-4 py-3 text-start font-medium">مالک</th>
                    <th class="px-4 py-3 text-start font-medium">اعضا</th>
                    <th class="px-4 py-3 text-start font-medium">اشتراک</th>
                    <th class="px-4 py-3 text-start font-medium">پیامک</th>
                    <th class="px-4 py-3 text-start font-medium">عضویت از</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($workspaces as $workspace)
                    @php
                        $owner = $workspace->owners->first();
                        $subscription = $workspace->subscriptions->first();
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.workspaces.show', $workspace) }}" class="font-medium hover:underline">{{ $workspace->name }}</a>
                            <span class="ms-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $workspace->type->label() }}</span>
                        </td>
                        <td class="px-4 py-3">
                            {{ $owner?->name ?: '—' }}
                            <span class="tabular block text-xs text-slate-500" dir="ltr">{{ $owner?->localPhone() }}</span>
                        </td>
                        <td class="tabular px-4 py-3">{{ number_format($workspace->members_count) }}</td>
                        <td class="px-4 py-3">
                            @if ($subscription)
                                {{ $subscription->status->label() }}
                                <span class="tabular block text-xs {{ $subscription->ends_at->isPast() ? 'text-red-600' : 'text-slate-500' }}">
                                    تا {{ JalaliDate::format(CarbonImmutable::parse($subscription->ends_at)) }}
                                </span>
                            @else
                                <span class="text-red-600">منقضی</span>
                            @endif
                        </td>
                        <td class="tabular px-4 py-3 text-xs">
                            {{ number_format($workspace->sms_used) }} / {{ number_format($workspace->sms_quota) }}
                            @unless ($workspace->sms_enabled)
                                <span class="block text-amber-700">خاموش</span>
                            @endunless
                        </td>
                        <td class="tabular px-4 py-3 text-xs text-slate-500">
                            {{ JalaliDate::format(CarbonImmutable::parse($workspace->created_at)) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $workspaces->links() }}</div>
@endif

@endsection
