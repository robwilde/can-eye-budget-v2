@php use Carbon\CarbonImmutable; @endphp
<div>
    <flux:modal wire:model="showModal" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">
                    {{ $transactionType === 'expense' ? __('Add Expense') : __('Add Income') }}
                </flux:heading>
                @if($date)
                    <flux:badge color="zinc">
                        {{ CarbonImmutable::parse($date)->format('D j M Y') }}
                    </flux:badge>
                @endif
            </div>

            <div class="flex gap-2">
                <flux:button
                    variant="{{ $transactionType === 'expense' ? 'primary' : 'ghost' }}"
                    wire:click="$set('transactionType', 'expense')"
                    type="button"
                    class="flex-1"
                >
                    {{ __('Expense') }}
                </flux:button>
                <flux:button
                    variant="{{ $transactionType === 'income' ? 'primary' : 'ghost' }}"
                    wire:click="$set('transactionType', 'income')"
                    type="button"
                    class="flex-1"
                >
                    {{ __('Income') }}
                </flux:button>
            </div>

            <flux:input
                wire:model.blur="descriptionInput"
                :label="__('Amount with description')"
                placeholder="4*15 zoo tickets (tip is ignored)"
                required
            />

            <div class="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                <flux:text size="sm" class="text-zinc-500">{{ __('Parsed amount') }}</flux:text>
                <div class="mt-1 text-lg font-semibold tabular-nums">
                    {{ $formatMoney($parsedAmount) }} — {{ __('Australian Dollar') }}
                </div>
            </div>

            <flux:select wire:model="accountId" :label="__('Account')" required>
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

            <div class="flex">
                <flux:spacer/>
                <flux:button type="submit" variant="primary">
                    {{ $transactionType === 'expense' ? __('Enter expense') : __('Enter income') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
