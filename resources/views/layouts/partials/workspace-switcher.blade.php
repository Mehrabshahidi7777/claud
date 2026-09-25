{{-- The workspace this page belongs to. A control only when there is
     somewhere else to go; most people belong to exactly one. --}}
@if ($otherWorkspaces->isEmpty())
    <div class="rounded-lg bg-slate-50 px-3 py-2">
        <p class="truncate text-sm font-medium text-slate-800">{{ $workspace->name }}</p>
        <p class="text-xs text-slate-500">{{ $workspace->type->label() }}</p>
    </div>
@else
    <details class="group relative">
        <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 hover:bg-slate-100">
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-slate-800">{{ $workspace->name }}</span>
                <span class="block text-xs text-slate-500">{{ $workspace->type->label() }}</span>
            </span>
            <svg class="size-4 shrink-0 text-slate-400 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
            </svg>
        </summary>

        <div class="absolute inset-x-0 z-40 mt-1 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
            <p class="px-3 pt-2 pb-1 text-[11px] text-slate-400">رفتن به</p>
            @foreach ($otherWorkspaces as $other)
                <form method="POST" action="{{ route('workspaces.switch', $other->id) }}">
                    @csrf
                    <button class="w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-slate-50">
                        <span class="block text-slate-800">{{ $other->name }}</span>
                        <span class="block text-xs text-slate-500">{{ $other->type->label() }}</span>
                    </button>
                </form>
            @endforeach
        </div>
    </details>
@endif
