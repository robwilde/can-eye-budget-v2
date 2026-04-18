@props([
    'sidebar' => false,
])

@if($sidebar)
    <a
        href="{{ $attributes->get('href', '/') }}"
        {{ $attributes->except('href')->class('flex items-center gap-2 px-2 h-14') }}
        data-flux-sidebar-brand
    >
        <img
            src="{{ asset('images/cib-logo.png') }}"
            alt="Can I Budget"
            class="size-10 rounded-lg border-[1.5px] border-cib-black object-cover shrink-0"
        />
        <div class="flex flex-col leading-tight truncate in-data-flux-sidebar-collapsed-desktop:hidden">
            <span class="font-display text-sm font-black text-white">Can I Budget</span>
            <span class="text-xs text-white/70">AU · Personal</span>
        </div>
    </a>
@else
    <flux:brand name="Can I Budget" {{ $attributes }}>
        <x-slot name="logo">
            <img
                src="{{ asset('images/cib-logo.png') }}"
                alt="Can I Budget"
                class="size-8 rounded-md border-[1.5px] border-cib-black object-cover"
            />
        </x-slot>
    </flux:brand>
@endif
