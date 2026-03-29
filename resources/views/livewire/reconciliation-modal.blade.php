@php use Carbon\CarbonImmutable; @endphp
<div>
    <flux:modal wire:model="showModal" class="md:w-lg">
        @if($plannedDetails)
            <div class="space-y-6">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Reconcile Transaction') }}</flux:heading>
                    @if($occurrenceDate)
                        <flux:badge color="zinc">
                            {{ CarbonImmutable::parse($occurrenceDate)->format('D j M Y') }}
                        </flux:badge>
                    @endif
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:text size="sm" class="text-zinc-500">{{ __('Planned') }}</flux:text>
                            <div class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $plannedDetails['description'] }}
                            </div>
                            @if($plannedDetails['category'])
                                <flux:text size="sm" class="mt-0.5 text-zinc-500">
                                    {{ $plannedDetails['category'] }} &middot; {{ $plannedDetails['account_name'] }}
                                </flux:text>
                            @else
                                <flux:text size="sm" class="mt-0.5 text-zinc-500">
                                    {{ $plannedDetails['account_name'] }}
                                </flux:text>
                            @endif
                        </div>
                        <div class="text-right">
                            <span class="text-lg font-semibold tabular-nums {{ $plannedDetails['direction'] === 'debit' ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                {{ $formatMoney($plannedDetails['amount']) }}
                            </span>
                        </div>
                    </div>
                </div>

                @if($linkedTransaction)
                    <div>
                        <flux:text size="sm" class="mb-2 font-medium text-zinc-700 dark:text-zinc-300">
                            <flux:icon.check-circle variant="mini" class="inline size-4 text-green-500"/>
                            {{ __('Linked transaction') }}
                        </flux:text>
                        <div class="rounded-lg border border-green-200 bg-green-50/50 p-3 dark:border-green-800 dark:bg-green-950/20">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $linkedTransaction['description'] }}
                                    </div>
                                    <flux:text size="sm" class="text-zinc-500">
                                        {{ CarbonImmutable::parse($linkedTransaction['post_date'])->format('D j M Y') }}
                                    </flux:text>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="font-semibold tabular-nums">
                                        {{ $formatMoney($linkedTransaction['amount']) }}
                                    </span>
                                    <flux:button
                                        variant="subtle"
                                        size="sm"
                                        wire:click="unlink({{ $linkedTransaction['id'] }})"
                                        wire:confirm="{{ __('Unlink this transaction?') }}"
                                    >
                                        {{ __('Unlink') }}
                                    </flux:button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if(!$linkedTransaction && count($suggestions) > 0)
                    <div>
                        <flux:text size="sm" class="mb-2 font-medium text-zinc-700 dark:text-zinc-300">
                            {{ __('Suggested matches') }}
                        </flux:text>
                        <div class="space-y-2">
                            @foreach($suggestions as $suggestion)
                                <div class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50/50 p-3 dark:border-amber-700 dark:bg-amber-950/20">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $suggestion['description'] }}
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <flux:text size="sm" class="text-zinc-500">
                                                {{ CarbonImmutable::parse($suggestion['post_date'])->format('D j M') }}
                                            </flux:text>
                                            @if($suggestion['amount_diff'] !== 0)
                                                <flux:badge size="sm" :color="$suggestion['amount_diff'] > 0 ? 'red' : 'green'">
                                                    {{ $suggestion['amount_diff'] > 0 ? '+' : '' }}{{ $formatMoney($suggestion['amount_diff']) }}
                                                </flux:badge>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="font-semibold tabular-nums">
                                            {{ $formatMoney($suggestion['amount']) }}
                                        </span>
                                        <flux:button
                                            variant="primary"
                                            size="sm"
                                            wire:click="link({{ $suggestion['id'] }})"
                                        >
                                            {{ __('Link') }}
                                        </flux:button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(!$linkedTransaction && count($suggestions) === 0)
                    <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-600">
                        <flux:icon.magnifying-glass class="mx-auto size-8 text-zinc-400"/>
                        <flux:text class="mt-2">{{ __('No matching transactions found near this date.') }}</flux:text>
                    </div>
                @endif

                <div class="flex justify-between">
                    <flux:button variant="subtle" wire:click="editPlanned" type="button">
                        {{ __('Edit planned') }}
                    </flux:button>
                    <flux:button variant="ghost" wire:click="$set('showModal', false)" type="button">
                        {{ __('Close') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
