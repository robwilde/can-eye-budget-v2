<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">
                @if($categoryName)
                    {{ $categoryName }} Transactions
                @else
                    All Transactions
                @endif
            </flux:heading>
            <flux:text class="mt-1">
                Showing
                @if($direction === 'incoming') incoming
                @elseif($direction === 'outgoing') outgoing
                @else all
                @endif
                transactions for the last {{ $period }}
            </flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="period" size="sm" class="w-auto">
                <flux:select.option value="7d">7 days</flux:select.option>
                <flux:select.option value="30d">30 days</flux:select.option>
                <flux:select.option value="90d">90 days</flux:select.option>
                <flux:select.option value="12m">12 months</flux:select.option>
            </flux:select>
            <flux:button variant="ghost" icon="arrow-left" href="{{ route('dashboard') }}" size="sm">Dashboard</flux:button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <flux:button
            wire:click="$set('direction', 'all')"
            :variant="$direction === 'all' ? 'primary' : 'ghost'"
            size="sm"
        >All</flux:button>
        <flux:button
            wire:click="$set('direction', 'outgoing')"
            :variant="$direction === 'outgoing' ? 'primary' : 'ghost'"
            size="sm"
        >Outgoing</flux:button>
        <flux:button
            wire:click="$set('direction', 'incoming')"
            :variant="$direction === 'incoming' ? 'primary' : 'ghost'"
            size="sm"
        >Incoming</flux:button>

        @if($accounts->count() > 1)
            <div class="mx-2 h-6 w-px bg-neutral-200 dark:bg-neutral-700"></div>

            <flux:button
                wire:click="$set('account', null)"
                :variant="$account === null ? 'primary' : 'ghost'"
                size="sm"
            >All Accounts</flux:button>
            @foreach($accounts as $acc)
                <flux:button
                    wire:click="$set('account', {{ $acc->id }})"
                    :variant="$account === $acc->id ? 'primary' : 'ghost'"
                    size="sm"
                >{{ $acc->name }}</flux:button>
            @endforeach
        @endif
    </div>

    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search transactions..." icon="magnifying-glass" size="sm" />

    @if($transactions->isEmpty())
        <div class="rounded-xl border border-neutral-200 p-8 text-center dark:border-neutral-700">
            <flux:icon.banknotes class="mx-auto size-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No transactions found</flux:heading>
            <flux:text class="mt-2">No transactions match your current filters.</flux:text>
            <div class="mt-6">
                <flux:button variant="primary" icon="arrow-left" href="{{ route('dashboard') }}">Back to Dashboard</flux:button>
            </div>
        </div>
    @else
        <div class="relative rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div wire:loading class="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-white/60 dark:bg-zinc-900/60">
                <flux:icon.arrow-path class="size-6 animate-spin text-zinc-400" />
            </div>
            <div class="flex items-center gap-4 border-b border-neutral-200 px-4 py-2 dark:border-neutral-700">
                <button wire:click="sort('description')" class="flex min-w-0 flex-1 items-center gap-1 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-200">
                    Description
                    @if($sortBy === 'description')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3" />
                    @endif
                </button>
                @if($account === null)
                    <span class="w-32 text-xs font-medium uppercase tracking-wider text-zinc-500">Account</span>
                @endif
                <button wire:click="sort('post_date')" class="flex w-24 items-center gap-1 text-xs font-medium uppercase tracking-wider text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-200">
                    Date
                    @if($sortBy === 'post_date')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3" />
                    @endif
                </button>
                <button wire:click="sort('amount')" class="flex w-28 items-center justify-end gap-1 text-xs font-medium uppercase tracking-wider text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-200">
                    Amount
                    @if($sortBy === 'amount')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3" />
                    @endif
                </button>
            </div>
            <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach($transactions as $transaction)
                    <div wire:key="txn-{{ $transaction->id }}" class="flex items-center gap-4 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <flux:heading size="sm" class="truncate">{{ $transaction->description }}</flux:heading>
                            @if($transaction->category)
                                <flux:badge size="sm" color="zinc" class="mt-1">{{ $transaction->category->name }}</flux:badge>
                            @endif
                        </div>
                        @if($account === null)
                            <flux:text size="sm" class="w-32 truncate">{{ $transaction->account?->name }}</flux:text>
                        @endif
                        <flux:text size="sm" class="w-24">{{ $transaction->post_date->format('d M Y') }}</flux:text>
                        <flux:text class="w-28 text-right tabular-nums font-medium {{ $direction === 'all' ? ($transaction->direction === \App\Enums\TransactionDirection::Debit ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400') : '' }}">
                            {{ $formatMoney($transaction->amount) }}
                        </flux:text>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-4">
            {{ $transactions->links() }}
        </div>
    @endif
</div>
