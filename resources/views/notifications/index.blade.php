@extends('layouts.app')

@section('title', 'اعلان‌ها')

@section('content')

<div class="mx-auto max-w-2xl">

    <div class="mb-4 flex items-baseline gap-3">
        <h1 class="text-lg font-bold">اعلان‌ها</h1>

        @if ($notifications->isNotEmpty())
            <form method="POST" action="{{ route('notifications.read') }}" class="ms-auto">
                @csrf
                <button class="text-sm text-slate-500 hover:text-slate-900">
                    همه را خوانده‌شده کن
                </button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <p class="text-slate-600">اعلانی نیست.</p>
            <p class="mt-2 text-xs text-slate-400">
                یادآوری‌های آرام قبل از سررسید اینجا می‌آیند — قبل از اینکه کار به پیامک برسد.
            </p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($notifications as $notification)
                {{-- Unread carries a dark border rather than a coloured
                     background: the list is read at a glance and a wall of
                     colour tells you nothing. --}}
                <div class="rounded-2xl border bg-white p-4
                            {{ $notification->read_at === null ? 'border-slate-900' : 'border-slate-200' }}">
                    <p class="text-sm">{{ $notification->data['message'] ?? '' }}</p>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ $notification->created_at->diffForHumans() }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif
</div>

@endsection
