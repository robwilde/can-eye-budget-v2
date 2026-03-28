@php use Carbon\CarbonImmutable; @endphp
<div>
    <flux:modal wire:model="showModal" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">
                    @if($editingTransactionId)
                        {{ $transactionType === 'expense' ? __('Edit Expense') : __('Edit Income') }}
                    @else
                        {{ $transactionType === 'expense' ? __('Add Expense') : __('Add Income') }}
                    @endif
                </flux:heading>
                <div class="flex items-center gap-2">
                    @if($isBasiqTransaction)
                        <flux:badge color="blue" size="sm" icon="cloud-arrow-down">
                            {{ __('Synced from bank') }}
                        </flux:badge>
                    @endif
                    @if($date)
                        <flux:badge color="zinc">
                            {{ CarbonImmutable::parse($date)->format('D j M Y') }}
                        </flux:badge>
                    @endif
                </div>
            </div>

            <div class="flex gap-2">
                <flux:button
                    variant="{{ $transactionType === 'expense' ? 'primary' : 'ghost' }}"
                    wire:click="$set('transactionType', 'expense')"
                    type="button"
                    class="flex-1"
                    :disabled="$isBasiqTransaction"
                >
                    {{ __('Expense') }}
                </flux:button>
                <flux:button
                    variant="{{ $transactionType === 'income' ? 'primary' : 'ghost' }}"
                    wire:click="$set('transactionType', 'income')"
                    type="button"
                    class="flex-1"
                    :disabled="$isBasiqTransaction"
                >
                    {{ __('Income') }}
                </flux:button>
            </div>

            <flux:input
                wire:model.blur="descriptionInput"
                :label="__('Amount with description')"
                placeholder="4*15 zoo tickets (tip is ignored)"
                required
                :disabled="$isBasiqTransaction"
            />

            @if($isBasiqTransaction)
                <flux:input
                    wire:model.blur="cleanDescription"
                    :label="__('Clean description')"
                    :placeholder="__('Your description for this transaction')"
                />
            @endif

            <div class="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                <flux:text size="sm" class="text-zinc-500">{{ __('Parsed amount') }}</flux:text>
                <div class="mt-1 text-lg font-semibold tabular-nums">
                    {{ $formatMoney($parsedAmount) }} — {{ __('Australian Dollar') }}
                </div>
            </div>

            <flux:select wire:model="accountId" :label="__('Account')" required :disabled="$isBasiqTransaction">
                <flux:select.option value="">{{ __('Select account') }}</flux:select.option>
                @foreach($accounts as $account)
                    <flux:select.option value="{{ $account->id }}">
                        {{ $account->name }} ({{ $formatMoney($account->balance) }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="categoryId" :label="__('Category')">
                <flux:select.option value="">{{ __('No category') }}</flux:select.option>
                @foreach($categories as $category)
                    <flux:select.option value="{{ $category->id }}">
                        {{ $category->fullPath() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="date"
                :label="__('Date')"
                type="date"
                required
                :disabled="$isBasiqTransaction"
            />

            <flux:textarea
                wire:model="notes"
                :label="__('Notes')"
                :placeholder="__('Optional notes')"
                rows="2"
            />

            <div class="flex">
                <flux:spacer/>
                <flux:button type="submit" variant="primary">
                    @if($editingTransactionId)
                        {{ $transactionType === 'expense' ? __('Update expense') : __('Update income') }}
                    @else
                        {{ $transactionType === 'expense' ? __('Enter expense') : __('Enter income') }}
                    @endif
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
