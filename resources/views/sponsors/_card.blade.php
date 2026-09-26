{{-- One sponsor. The whole card is the link when they have a site, marked
     sponsored so search engines do not read it as an endorsement. --}}
@php $href = $sponsor->visitUrl(); @endphp

<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" target="_blank" rel="sponsored noopener" @endif
    class="flex h-full items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 {{ $href ? 'transition hover:border-brand-300 hover:shadow-sm' : '' }}">
    @if ($sponsor->logoUrl())
        <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}" loading="lazy"
             class="size-14 shrink-0 rounded-xl border border-slate-100 bg-white object-contain p-1">
    @endif
    <span class="min-w-0">
        <span class="block font-medium text-slate-900">{{ $sponsor->name }}</span>
        @if ($sponsor->description)
            <span class="mt-1 block text-sm leading-6 text-slate-600">{{ $sponsor->description }}</span>
        @endif
    </span>
</{{ $href ? 'a' : 'div' }}>
