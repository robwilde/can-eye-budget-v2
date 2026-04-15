@php use App\Enums\AccountClass; @endphp
<div class="space-y-6">
    @if($pendingSuggestionCount > 0)
        <flux:card class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-amber-500/10 p-2 dark:bg-amber-400/10">
                    <flux:icon.sparkles class="size-5 text-amber-500 dark:text-amber-400"/>
                </div>
                <div>
                    <flux:heading size="sm">
                        {{ $pendingSuggestionCount }} {{ Str::plural('suggestion', $pendingSuggestionCount) }} ready to review
                    </flux:heading>
                    <flux:text size="sm">We've analysed your transactions and have recommendations waiting.</flux:text>
                </div>
            </div>
            <flux:button variant="primary" size="sm" href="{{ route('connect-bank') }}">Review</flux:button>
        </flux:card>
    @endif

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
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:card class="relative flex min-h-40 flex-col justify-between overflow-hidden">
                <div>
                    <div class="flex items-center justify-between">
                        <flux:heading size="xl">Owed</flux:heading>
                        <div class="rounded-lg bg-red-500/10 p-2 dark:bg-red-400/10">
                            <flux:icon.credit-card class="size-5 text-red-500 dark:text-red-400"/>
                        </div>
                    </div>
                    <p class="mt-3 text-4xl font-bold tracking-tight tabular-nums text-red-600 dark:text-red-500">
                        {{ $formatMoney($totalOwed) }}
                    </p>
                </div>
                <flux:text size="sm" class="mt-3">{{ $debtAccountCount }} {{ Str::plural('account', $debtAccountCount) }}</flux:text>
            </flux:card>

            <flux:card class="relative flex min-h-40 flex-col justify-between overflow-hidden">
                <div>
                    <div class="flex items-center justify-between">
                        <flux:heading size="xl">Available</flux:heading>
                        <div class="rounded-lg p-2 {{ $totalAvailable >= 0 ? 'bg-green-500/10 dark:bg-green-400/10' : 'bg-red-500/10 dark:bg-red-400/10' }}">
                            <flux:icon.wallet class="size-5 {{ $totalAvailable >= 0 ? 'text-green-500 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}"/>
                        </div>
                    </div>
                    <p class="mt-3 text-4xl font-bold tracking-tight tabular-nums {{ $totalAvailable >= 0 ? 'text-green-600 dark:text-green-500' : 'text-red-600 dark:text-red-500' }}">
                        {{ $formatMoney($totalAvailable) }}
                    </p>
                </div>
                <div class="mt-3">
                    @if($hasPayCycle && $buffer !== null)
                        @php
                            $bufferTextClass = match(true) {
                                $buffer > 0 => 'text-green-600 dark:text-green-500',
                                $buffer < 0 => 'text-red-600 dark:text-red-500',
                                default => 'text-zinc-500 dark:text-zinc-400',
                            };
                        @endphp
                        <div class="flex items-center gap-1.5">
                            @if($buffer > 0)
                                <flux:icon.arrow-trending-up class="size-4 text-green-600 dark:text-green-500"/>
                            @elseif($buffer < 0)
                                <flux:icon.arrow-trending-down class="size-4 text-red-600 dark:text-red-500"/>
                            @else
                                <flux:icon.minus class="size-4 text-zinc-500 dark:text-zinc-400"/>
                            @endif
                            <p class="text-sm font-semibold tabular-nums {{ $bufferTextClass }}">
                                @if($buffer > 0)
                                    +{{ $formatMoney($buffer) }} above what you need
                                @elseif($buffer < 0)
                                    {{ $formatMoney($buffer) }} below what you need
                                @else
                                    {{ $formatMoney(0) }} — right on target
                                @endif
                            </p>
                        </div>
                    @elseif($lastSynced)
                        <flux:text size="sm">Last synced {{ $lastSynced->diffForHumans() }}</flux:text>
                    @endif
                </div>
            </flux:card>

            <flux:card class="relative flex min-h-40 flex-col justify-between overflow-hidden">
                <div>
                    <div class="flex items-center justify-between">
                        <flux:heading size="xl">Needed</flux:heading>
                        <div class="rounded-lg bg-zinc-500/10 p-2 dark:bg-zinc-400/10">
                            <flux:icon.banknotes class="size-5 text-zinc-500 dark:text-zinc-400"/>
                        </div>
                    </div>
                    @if($hasPayCycle && $projectedSpend !== null)
                        <p class="mt-3 text-4xl font-bold tracking-tight tabular-nums text-zinc-900 dark:text-white">
                            {{ $formatMoney($projectedSpend) }}
                        </p>
                    @else
                        <p class="mt-3 text-4xl font-bold tracking-tight tabular-nums text-zinc-400 dark:text-zinc-500">
                            —
                        </p>
                    @endif
                </div>
                <div class="mt-3">
                    @if($hasPayCycle && $projectedSpend !== null)
                        <div class="flex items-center gap-1.5">
                            <flux:icon.calendar class="size-4 text-zinc-400 dark:text-zinc-500"/>
                            <flux:text size="sm">{{ $daysUntilPay }} {{ Str::plural('day', $daysUntilPay) }} until payday</flux:text>
                        </div>
                    @else
                        <flux:link href="{{ route('pay-cycle.edit') }}" variant="subtle" class="text-sm">Set up pay cycle →</flux:link>
                    @endif
                </div>
            </flux:card>
        </div>

        @if($hasPayCycle && $lastSynced)
            <div class="text-center">
                <flux:text size="sm">Last synced {{ $lastSynced->diffForHumans() }}</flux:text>
            </div>
        @endif

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
                                @if($account->type === AccountClass::CreditCard)
                                    <flux:text size="sm" class="tabular-nums text-zinc-500">Current {{ $formatMoney($account->balance) }}</flux:text>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
