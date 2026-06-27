@php
    use App\Casts\MoneyCast;
    use App\Enums\RecurrenceFrequency;
@endphp

<flux:card>
    <div class="space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="lg">Recurring transactions</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">Find regular payments and turn them into planned transactions.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:field class="min-w-56">
                    <flux:label>Account</flux:label>
                    <flux:select wire:model.live="accountId" size="sm">
                        @if($accounts->isEmpty())
                            <flux:select.option value="">No accounts</flux:select.option>
                        @endif

                        @foreach($accounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:button
                    variant="primary"
                    size="sm"
                    wire:click="findRecurring"
                    wire:loading.attr="disabled"
                    wire:target="findRecurring"
                    :disabled="$accounts->isEmpty()"
                >
                    <span wire:loading.remove wire:target="findRecurring">Find recurring</span>
                    <span wire:loading wire:target="findRecurring">Finding...</span>
                </flux:button>
            </div>
        </div>

        @forelse($suggestions as $suggestion)
            <div wire:key="recurring-suggestion-{{ $suggestion->id }}" class="rounded-lg border border-neutral-200 p-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="sm">{{ $suggestion->payload['clean_description'] }}</flux:heading>
                            <flux:badge size="sm" color="zinc">
                                {{ RecurrenceFrequency::from($suggestion->payload['frequency'])->label() }}
                            </flux:badge>
                            <flux:badge size="sm" color="blue">
                                {{ (int) round(((float) $suggestion->payload['confidence_score']) * 100) }}% confidence
                            </flux:badge>
                            <flux:badge size="sm" color="purple">
                                {{ count($suggestion->payload['matched_transaction_ids']) }} matches
                            </flux:badge>
                        </div>

                        <flux:text size="sm" class="mt-1 font-bold tabular-nums text-zinc-900">
                            {{ MoneyCast::format(abs((int) $suggestion->payload['amount'])) }}
                        </flux:text>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <x-category-combobox
                            wire:model="recurringCategories.{{ $suggestion->id }}"
                            :categories="$categories"
                            placeholder="No category"
                            size="sm"
                            class="w-full sm:w-48"
                        />

                        <div class="flex items-center gap-2">
                            <flux:button
                                variant="primary"
                                size="sm"
                                wire:key="accept-recurring-{{ $suggestion->id }}"
                                wire:click="accept({{ $suggestion->id }})"
                            >
                                Accept
                            </flux:button>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                wire:key="dismiss-recurring-{{ $suggestion->id }}"
                                wire:click="dismiss({{ $suggestion->id }})"
                            >
                                Dismiss
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center rounded-lg border border-dashed border-neutral-200 py-10 text-center">
                <flux:icon.arrow-path class="mb-3 size-10 text-zinc-400" />
                <flux:heading size="sm">No recurring patterns found for this account — run a scan.</flux:heading>
            </div>
        @endforelse
    </div>
</flux:card>
