<div class="space-y-6">
    @if($accounts->isEmpty())
        <div class="rounded-xl border border-neutral-200 p-8 text-center dark:border-neutral-700">
            <flux:icon.building-library class="mx-auto size-12 text-zinc-400"/>
            <flux:heading size="lg" class="mt-4">No linked accounts</flux:heading>
            <flux:text class="mt-2">Connect your bank to see your accounts and what you have available.</flux:text>
            <div class="mt-6">
                <flux:button variant="primary" icon="plus" href="{{ route('connect-bank') }}">Connect Bank</flux:button>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-neutral-200 p-6 text-center dark:border-neutral-700">
            <flux:text>Available to Spend</flux:text>
            <p class="mt-1 text-5xl font-bold tracking-tight tabular-nums sm:text-6xl md:text-7xl {{ $availableToSpend >= 0 ? 'text-green-600 dark:text-green-500' : 'text-red-600 dark:text-red-500' }}">
                {{ $formatMoney($availableToSpend) }}
            </p>
            @if($lastSynced)
                <flux:text size="sm" class="mt-2">Last synced {{ $lastSynced->diffForHumans() }}</flux:text>
            @endif
        </div>

        <div x-data="{ open: false }">
            <button
                x-on:click="open = !open"
                class="flex w-full items-center justify-between rounded-xl border border-neutral-200 px-4 py-3 text-left dark:border-neutral-700"
            >
                <flux:text class="font-medium">Account breakdown</flux:text>
                <flux:icon.chevron-down
                    class="size-5 text-zinc-400 transition-transform duration-200"
                    ::class="open ? 'rotate-180' : ''"
                />
            </button>

            <div
                x-show="open"
                x-collapse
                class="mt-2 rounded-xl border border-neutral-200 dark:border-neutral-700"
            >
                <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach($accounts as $account)
                        <div wire:key="account-{{ $account->id }}" class="flex items-center justify-between px-4 py-3">
                            <div>
                                <flux:heading size="sm">{{ $account->name }}</flux:heading>
                                <flux:text size="sm">{{ $account->institution }}</flux:text>
                            </div>
                            <div class="text-right">
                                <flux:text class="tabular-nums font-medium">{{ $formatMoney($account->availableBalance()) }}</flux:text>
                                @if($account->type === \App\Enums\AccountClass::CreditCard)
                                    <flux:text size="sm" class="tabular-nums text-zinc-500">Current {{ $formatMoney($account->balance) }}</flux:text>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="flex justify-center">
            <flux:button variant="primary" icon="plus" href="{{ route('connect-bank') }}">Connect Bank</flux:button>
        </div>
    @endif
</div>
