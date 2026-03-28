@php use Carbon\CarbonImmutable; @endphp
<div data-testid="calendar-view">
    <div class="rounded-xl border border-neutral-200 dark:border-neutral-700">
        <div class="flex items-center justify-between p-4">
            <div class="flex items-center gap-2">
                <flux:button wire:click="previousMonth" variant="ghost" icon="chevron-left" size="sm"/>
                <flux:heading>{{ $this->calendarData['monthLabel'] }}</flux:heading>
                <flux:button wire:click="nextMonth" variant="ghost" icon="chevron-right" size="sm"/>
            </div>
            @unless($this->calendarData['isCurrentMonth'])
                <flux:button wire:click="goToToday" variant="subtle" size="sm">Today</flux:button>
            @endunless
        </div>

        <flux:separator/>

        <div class="hidden md:block">
            <div class="grid grid-cols-7 border-b border-neutral-200 dark:border-neutral-700">
                @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                    <div class="px-2 py-2 text-center text-xs font-medium uppercase tracking-wider text-zinc-500">
                        {{ $day }}
                    </div>
                @endforeach
            </div>

            @foreach($this->calendarData['weeks'] as $weekIndex => $week)
                <div class="grid grid-cols-7 {{ !$loop->last ? 'border-b border-neutral-200 dark:border-neutral-700' : '' }}">
                    @foreach($week as $day)
                        <div
                                wire:key="day-{{ $day['fullDate'] }}"
                                wire:click="$dispatch('open-transaction-modal', { date: '{{ $day['fullDate'] }}' })"
                                class="min-h-28 cursor-pointer border-r border-neutral-200 p-1.5 last:border-r-0 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-zinc-800 {{ !$day['isCurrentMonth'] ? 'bg-zinc-50 dark:bg-zinc-900/50' : '' }}"
                        >
                            <div class="mb-1 text-right text-xs font-medium {{ $day['isToday'] ? 'flex items-center justify-end' : '' }} {{ !$day['isCurrentMonth'] ? 'text-zinc-400 dark:text-zinc-600' : 'text-zinc-700 dark:text-zinc-300' }}">
                                @if($day['isToday'])
                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-indigo-600 text-white">{{ $day['date'] }}</span>
                                @else
                                    {{ $day['date'] }}
                                @endif
                            </div>
                            @if(count($day['transactions']) > 0)
                                <div class="max-h-24 space-y-0.5 overflow-y-auto">
                                    @foreach($day['transactions'] as $txn)
                                        @php
                                            $isPlanned = ($txn['type'] ?? 'actual') === 'planned';
                                            $bgColor = match(true) {
                                                $isPlanned && $txn['direction'] === 'debit' => 'border border-dashed border-red-300 bg-red-50/50 dark:border-red-800 dark:bg-red-950/20',
                                                $isPlanned => 'border border-dashed border-green-300 bg-green-50/50 dark:border-green-800 dark:bg-green-950/20',
                                                ($txn['isTransfer'] ?? false) => 'bg-blue-50 dark:bg-blue-950/30',
                                                $txn['direction'] === 'debit' => 'bg-red-50 dark:bg-red-950/30',
                                                default => 'bg-green-50 dark:bg-green-950/30',
                                            };
                                            $amountColor = match(true) {
                                                $isPlanned && $txn['direction'] === 'debit' => 'text-red-400 dark:text-red-600',
                                                $isPlanned => 'text-green-400 dark:text-green-600',
                                                ($txn['isTransfer'] ?? false) => 'text-blue-600 dark:text-blue-400',
                                                $txn['direction'] === 'debit' => 'text-red-600 dark:text-red-400',
                                                default => 'text-green-600 dark:text-green-400',
                                            };
                                        @endphp
                                        @if($isPlanned)
                                            <button
                                                type="button"
                                                wire:click.stop="$dispatch('edit-planned-transaction', { id: {{ $txn['planned_transaction_id'] }} })"
                                                class="flex w-full cursor-pointer items-center justify-between gap-1 rounded px-1 py-0.5 text-xs hover:ring-1 hover:ring-indigo-300 dark:hover:ring-indigo-600 {{ $bgColor }}"
                                            >
                                                <span class="flex items-center gap-0.5 truncate {{ !$day['isCurrentMonth'] ? 'text-zinc-400 dark:text-zinc-600' : 'text-zinc-500 dark:text-zinc-500' }}">
                                                    <flux:icon.clock variant="mini" class="size-3 shrink-0"/>
                                                    {{ $txn['category'] }}
                                                </span>
                                                <span class="shrink-0 tabular-nums font-medium {{ $amountColor }}">{{ $formatMoney($txn['amount']) }}</span>
                                            </button>
                                        @else
                                            <button
                                                type="button"
                                                wire:click.stop="$dispatch('edit-transaction', { id: {{ $txn['id'] }} })"
                                                class="flex w-full cursor-pointer items-center justify-between gap-1 rounded px-1 py-0.5 text-xs hover:ring-1 hover:ring-indigo-300 dark:hover:ring-indigo-600 {{ $bgColor }}"
                                            >
                                                <span class="truncate {{ !$day['isCurrentMonth'] ? 'text-zinc-400 dark:text-zinc-600' : 'text-zinc-600 dark:text-zinc-400' }}">{{ $txn['category'] }}</span>
                                                <span class="shrink-0 tabular-nums font-medium {{ $amountColor }}">{{ $formatMoney($txn['amount']) }}</span>
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="md:hidden">
            @php
                $daysWithTransactions = collect($this->calendarData['weeks'])
                    ->flatten(1)
                    ->filter(fn ($day) => $day['isCurrentMonth'] && count($day['transactions']) > 0);
            @endphp

            @if($daysWithTransactions->isEmpty())
                <div class="p-8 text-center">
                    <flux:icon.calendar class="mx-auto size-12 text-zinc-400"/>
                    <flux:heading size="lg" class="mt-4">No transactions</flux:heading>
                    <flux:text class="mt-2">No transactions found for {{ $this->calendarData['monthLabel'] }}.</flux:text>
                </div>
            @else
                <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach($daysWithTransactions as $day)
                        <div
                            wire:key="mobile-{{ $day['fullDate'] }}"
                            wire:click="$dispatch('open-transaction-modal', { date: '{{ $day['fullDate'] }}' })"
                            class="cursor-pointer px-4 py-3"
                        >
                            <div class="mb-2 text-sm font-medium {{ $day['isToday'] ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-700 dark:text-zinc-300' }}">
                                {{ CarbonImmutable::parse($day['fullDate'])->format('D j M') }}
                            </div>
                            <div class="space-y-1">
                                @foreach($day['transactions'] as $txn)
                                    @php
                                        $isPlanned = ($txn['type'] ?? 'actual') === 'planned';
                                        $amountColor = match(true) {
                                            $isPlanned && $txn['direction'] === 'debit' => 'text-red-400 dark:text-red-600',
                                            $isPlanned => 'text-green-400 dark:text-green-600',
                                            ($txn['isTransfer'] ?? false) => 'text-blue-600 dark:text-blue-400',
                                            $txn['direction'] === 'debit' => 'text-red-600 dark:text-red-400',
                                            default => 'text-green-600 dark:text-green-400',
                                        };
                                    @endphp
                                    @if($isPlanned)
                                        <button
                                            type="button"
                                            wire:click.stop="$dispatch('edit-planned-transaction', { id: {{ $txn['planned_transaction_id'] }} })"
                                            class="flex w-full cursor-pointer items-center justify-between rounded-md border border-dashed {{ $txn['direction'] === 'debit' ? 'border-red-300 dark:border-red-800' : 'border-green-300 dark:border-green-800' }} px-2 py-1 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                        >
                                            <span class="flex items-center gap-1 truncate text-zinc-500 dark:text-zinc-500">
                                                <flux:icon.clock variant="mini" class="size-3.5 shrink-0"/>
                                                {{ $txn['category'] }}
                                            </span>
                                            <span class="shrink-0 tabular-nums font-medium {{ $amountColor }}">{{ $formatMoney($txn['amount']) }}</span>
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click.stop="$dispatch('edit-transaction', { id: {{ $txn['id'] }} })"
                                            class="flex w-full cursor-pointer items-center justify-between rounded-md px-2 py-1 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                        >
                                            <span class="truncate text-zinc-600 dark:text-zinc-400">{{ $txn['category'] }}</span>
                                            <span class="shrink-0 tabular-nums font-medium {{ $amountColor }}">{{ $formatMoney($txn['amount']) }}</span>
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        @if(collect($this->calendarData['weeks'])->flatten(1)->every(fn ($day) => count($day['transactions']) === 0))
            <div class="hidden p-8 text-center md:block">
                <flux:icon.calendar class="mx-auto size-12 text-zinc-400"/>
                <flux:heading size="lg" class="mt-4">No transactions</flux:heading>
                <flux:text class="mt-2">No transactions found for {{ $this->calendarData['monthLabel'] }}.</flux:text>
            </div>
        @endif
    </div>
</div>
