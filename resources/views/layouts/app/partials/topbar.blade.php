@php
    $titles = [
        'dashboard' => ['Dashboard', 'Can I Budget?'],
        'calendar' => ['Calendar', now()->format('F Y')],
        'transactions' => ['Transactions', 'All activity'],
        'accounts' => ['Accounts', 'Connected banks'],
        'connect-bank' => ['Connect', 'Link a bank'],
        'rules' => ['Rules', 'Automations'],
    ];

    [$crumb, $title] = $titles[request()->route()?->getName()] ?? ['Can I Budget', ''];
@endphp

<header data-flux-header style="grid-area: header" class="sticky top-0 z-30 flex items-center justify-between gap-4 border-b-2 border-cib-black bg-bg-page px-6 py-3">
    <div>
        <div class="text-xs font-bold uppercase tracking-wide text-fg-3">{{ $crumb }}</div>
        <h1 class="font-display text-2xl font-black text-fg-1">{{ $title }}</h1>
    </div>

    <div class="flex items-center gap-3">
        @if($shellDaysUntilNextPay !== null)
            <span
                    data-testid="topbar-payday-chip"
                    class="inline-flex items-center gap-2 rounded-pill bg-cib-black py-1 pl-1 pr-3 text-xs font-bold text-white shadow-pop-sm"
            >
                <span class="grid size-6.5 place-items-center rounded-full border-[1.5px] border-cib-black bg-cib-yellow-400 font-display text-[11px] font-black text-cib-black">
                    {{ $shellDaysUntilNextPay }}d
                </span>
                {{ $shellDaysUntilNextPay === 0 ? "It's payday" : 'until next payday' }}
            </span>
        @endif

        @if($shellLatestSync)
            <span
                    data-testid="topbar-sync-chip"
                    class="hidden items-center gap-2 rounded-pill border border-cib-green-300 bg-money-available-soft px-3 py-1.5 text-xs font-bold text-cib-green-600 sm:inline-flex"
            >
                <span class="size-1.75 rounded-full bg-cib-green-500 ring-4 ring-cib-green-500/20"></span>
                Synced {{ $shellSyncedHuman }} · Basiq
            </span>
        @endif

        <a
                href="{{ route('connect-bank') }}"
                wire:navigate
                data-testid="topbar-refresh"
                aria-label="Refresh Basiq sync"
                class="hidden size-9 items-center justify-center rounded-md border-2 border-cib-black bg-white shadow-pop-sm hover:bg-cib-cream-50 sm:inline-flex"
        >
            <flux:icon name="arrow-path" class="size-4"/>
        </a>

        <button
                type="button"
                wire:click="$dispatch('open-transaction-modal', { date: '{{ now()->toDateString() }}' })"
                data-testid="topbar-log-spend"
                class="inline-flex items-center gap-2 rounded-md border-2 border-cib-black bg-cib-yellow-400 px-4 py-2 text-sm font-bold text-cib-black shadow-pop transition-transform hover:-translate-y-px"
        >
            <flux:icon name="plus" class="size-4"/>
            Log spend
        </button>
    </div>
</header>
