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
            <flux:text class="mt-1">Showing debits for the last {{ $period }}</flux:text>
        </div>
        <div class="flex items-center gap-3">
            <flux:select wire:model.live="period" size="sm" class="w-auto">
                <flux:select.option value="7d">7 days</flux:select.option>
                <flux:select.option value="30d">30 days</flux:select.option>
                <flux:select.option value="90d">90 days</flux:select.option>
                <flux:select.option value="12m">12 months</flux:select.option>
            </flux:select>
            <flux:button variant="ghost" icon="arrow-left" href="{{ route('dashboard') }}" size="sm">Dashboard</flux:button>
        </div>
    </div>

    @if($transactions->isEmpty())
        <div class="rounded-xl border border-neutral-200 p-8 text-center dark:border-neutral-700">
            <flux:icon.banknotes class="mx-auto size-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No transactions found</flux:heading>
            <flux:text class="mt-2">No spending transactions match your current filters.</flux:text>
            <div class="mt-6">
                <flux:button variant="primary" icon="arrow-left" href="{{ route('dashboard') }}">Back to Dashboard</flux:button>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach($transactions as $transaction)
                    <div wire:key="txn-{{ $transaction->id }}" class="flex items-center justify-between px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <flux:heading size="sm" class="truncate">{{ $transaction->description }}</flux:heading>
                            <div class="mt-1 flex items-center gap-2">
                                <flux:text size="sm">{{ $transaction->post_date->format('d M Y') }}</flux:text>
                                @if($transaction->category)
                                    <flux:badge size="sm" color="zinc">{{ $transaction->category->name }}</flux:badge>
                                @endif
                            </div>
                        </div>
                        <flux:text class="tabular-nums font-medium">{{ $formatMoney($transaction->amount) }}</flux:text>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-4">
            {{ $transactions->links() }}
        </div>
    @endif
</div>
