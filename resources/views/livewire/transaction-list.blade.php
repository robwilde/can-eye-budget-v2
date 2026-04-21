@php
    use App\Enums\TransactionDirection;
    use Carbon\CarbonImmutable;
@endphp
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
                @if($direction === 'incoming')
                    incoming
                @elseif($direction === 'outgoing')
                    outgoing
                @else
                    all
                @endif
                transactions — {{ $periodLabel }}
            </flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="period" size="sm" class="w-auto">
                <flux:select.option value="7d">Last 7 Days</flux:select.option>
                <flux:select.option value="this-month">This Month</flux:select.option>
                <flux:select.option value="3m">Last 3 Months</flux:select.option>
                <flux:select.option value="6m">Last 6 Months</flux:select.option>
                <flux:select.option value="1y">Last Year</flux:select.option>
                @if($hasPayCycle)
                    <flux:select.option value="pay-cycle">Pay Cycle</flux:select.option>
                @endif
                <flux:select.option value="all">All Time</flux:select.option>
                <flux:select.option value="custom">Custom Range</flux:select.option>
            </flux:select>
            @if($showCustomRange)
                <flux:input type="date" wire:model.live="from" size="sm" class="w-auto"/>
                <flux:input type="date" wire:model.live="to" size="sm" class="w-auto"/>
            @endif
            <flux:button variant="ghost" icon="arrow-left" href="{{ route('dashboard') }}" size="sm">Dashboard</flux:button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-cib.filter-toggle
            :options="[
                ['value' => 'all', 'label' => 'All'],
                ['value' => 'outgoing', 'label' => 'Outgoing', 'tone' => 'out'],
                ['value' => 'incoming', 'label' => 'Incoming', 'tone' => 'inc'],
            ]"
            :selected="$direction"
            wire-model="direction"
        />

        @if($accounts->count() > 1)
            <x-cib.filter-toggle
                :options="collect([['value' => null, 'label' => 'All Accounts']])
                    ->concat($accounts->map(fn ($acc) => ['value' => $acc->id, 'label' => $acc->name]))
                    ->all()"
                :selected="$account"
                wire-model="account"
            />
        @endif
    </div>

    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search transactions..." icon="magnifying-glass" size="sm"/>

    @if($transactions->isEmpty())
        <x-cib.empty-state
            icon="banknotes"
            title="No transactions found"
            description="No transactions match your current filters."
        >
            <x-slot:action>
                <flux:button variant="primary" icon="arrow-left" href="{{ route('dashboard') }}">
                    Back to Dashboard
                </flux:button>
            </x-slot:action>
        </x-cib.empty-state>
    @else
        <div class="relative">
            <div wire:loading class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-zinc-900/60">
                <flux:icon.arrow-path class="size-6 animate-spin text-zinc-400"/>
            </div>

            <div class="flex items-center gap-4 px-4 py-2">
                <button wire:click="sort('description')"
                        class="cib-label flex min-w-0 flex-1 items-center gap-1 text-left">
                    Description
                    @if($sortBy === 'description')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3"/>
                    @endif
                </button>
                @if($account === null)
                    <span class="cib-label w-32">Account</span>
                @endif
                <button wire:click="sort('post_date')"
                        class="cib-label flex w-24 items-center gap-1">
                    Date
                    @if($sortBy === 'post_date')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3"/>
                    @endif
                </button>
                <button wire:click="sort('amount')"
                        class="cib-label flex w-28 items-center justify-end gap-1">
                    Amount
                    @if($sortBy === 'amount')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3"/>
                    @endif
                </button>
            </div>

            <div class="agenda">
                @foreach($grouped as $dateKey => $dayTxns)
                    <section wire:key="group-{{ $dateKey }}" class="agenda-group">
                        <x-cib.sec-head :title="CarbonImmutable::parse($dateKey)->format('j D M')"/>
                        <div class="day-card">
                            @foreach($dayTxns as $transaction)
                                @php
                                    $tone = $transaction->direction === TransactionDirection::Credit ? 'inc' : 'out';
                                    $metaParts = array_filter([
                                        $transaction->category?->name,
                                        $account === null ? $transaction->account?->name : null,
                                    ]);
                                @endphp
                                <x-cib.tx-row
                                    wire:key="txn-{{ $transaction->id }}"
                                    :transaction-id="$transaction->id"
                                    :name="$transaction->description"
                                    :amount="$transaction->amount"
                                    :tone="$tone"
                                    :icon="$transaction->category?->resolveIcon()"
                                >
                                    @if(! empty($metaParts))
                                        <x-slot:meta>{{ implode(' · ', $metaParts) }}</x-slot:meta>
                                    @endif
                                </x-cib.tx-row>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        <x-cib.card class="mt-4">
            <div class="flex items-center justify-between gap-4">
                {{ $transactions->links() }}
            </div>
        </x-cib.card>
    @endif
</div>
