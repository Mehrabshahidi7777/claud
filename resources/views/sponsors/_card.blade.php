{{-- One sponsor. The whole card is the link when they have a site, marked
     sponsored so search engines do not read it as an endorsement. --}}
@php
    $href = $sponsor->visitUrl();
    $site = $sponsor->displayHost();
@endphp

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
        @if ($site)
            <span class="mt-2 inline-flex items-center gap-1 font-latin text-xs text-brand-700" dir="ltr">
                {{ $site }}
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                </svg>
            </span>
        @endif
    </span>
</{{ $href ? 'a' : 'div' }}>
