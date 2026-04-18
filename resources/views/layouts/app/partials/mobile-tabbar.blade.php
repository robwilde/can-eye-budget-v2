@php
    $tabs = [
        ['label' => 'Home', 'icon' => 'home', 'route' => 'dashboard'],
        ['label' => 'Calendar', 'icon' => 'calendar-days', 'route' => 'calendar'],
    ];
@endphp

<nav
    data-testid="mobile-tabbar"
    class="lg:hidden fixed inset-x-4 bottom-4 z-40 flex items-center justify-between rounded-[26px] border-2 border-cib-black bg-cib-black px-2 py-2 shadow-pop"
>
    @foreach($tabs as $tab)
        <a
            href="{{ route($tab['route']) }}"
            wire:navigate
            class="flex flex-col items-center gap-1 rounded-xl px-3 py-2 text-xs font-bold {{ request()->routeIs($tab['route']) ? 'bg-cib-yellow-400 text-cib-black' : 'text-white' }}"
        >
            <flux:icon :name="$tab['icon']" class="size-5" />
            {{ $tab['label'] }}
        </a>
    @endforeach

    <button
        type="button"
        wire:click="$dispatch('open-transaction-modal', { date: '{{ now()->toDateString() }}' })"
        data-testid="mobile-tabbar-fab"
        aria-label="Log a transaction"
        class="-translate-y-3.5 inline-flex flex-col items-center gap-1 rounded-[20px] border-2 border-cib-black bg-cib-teal-400 px-3 py-2.5 text-white shadow-pop-sm"
    >
        <flux:icon name="plus" class="size-5" />
    </button>

    <a
        href="{{ route('transactions') }}"
        wire:navigate
        class="flex flex-col items-center gap-1 rounded-xl px-3 py-2 text-xs font-bold {{ request()->routeIs('transactions') ? 'bg-cib-yellow-400 text-cib-black' : 'text-white' }}"
    >
        <flux:icon name="chart-pie" class="size-5" />
        Spend
    </a>

    <a
        href="{{ route('profile.edit') }}"
        wire:navigate
        class="flex flex-col items-center gap-1 rounded-xl px-3 py-2 text-xs font-bold {{ request()->routeIs('profile.*') ? 'bg-cib-yellow-400 text-cib-black' : 'text-white' }}"
    >
        <flux:icon name="ellipsis-horizontal" class="size-5" />
        More
    </a>
</nav>
