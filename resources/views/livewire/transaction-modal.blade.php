@php
    use App\Enums\RecurrenceFrequency;
    use Carbon\CarbonImmutable;

    $typeColor = match($transactionType) {
        'expense' => 'text-red-600 dark:text-red-400',
        'income' => 'text-green-600 dark:text-green-400',
        'transfer' => 'text-amber-600 dark:text-amber-400',
        default => '',
    };

    $headerBg = match($transactionType) {
        'expense' => 'bg-red-50 dark:bg-red-950/30',
        'income' => 'bg-green-50 dark:bg-green-950/30',
        'transfer' => 'bg-amber-50 dark:bg-amber-950/30',
        default => '',
    };

    $borderColor = match($transactionType) {
        'expense' => 'border-l-4 border-l-red-500',
        'income' => 'border-l-4 border-l-green-500',
        'transfer' => 'border-l-4 border-l-amber-500',
        default => '',
    };

    $buttonClasses = match($transactionType) {
        'expense' => 'bg-red-600! hover:bg-red-700! text-white!',
        'income' => 'bg-green-600! hover:bg-green-700! text-white!',
        'transfer' => 'bg-amber-600! hover:bg-amber-700! text-white!',
        default => '',
    };
@endphp
<div>
    <flux:modal wire:model="showModal" class="md:w-lg {{ $borderColor }}">
        <form wire:submit="save" class="space-y-6">
            <div class="-mx-6 -mt-6 mb-6 rounded-t-xl px-6 py-4 {{ $headerBg }}">
                <div class="flex items-center justify-between">
                    @if($isBasiqTransaction || $editingTransactionId || $editingPlannedTransactionId)
                        <flux:heading size="lg" class="{{ $typeColor }}">
                            @if($transactionType === 'transfer')
                                {{ __('Between Accounts') }}
                            @elseif($transactionType === 'income')
                                {{ __('Income') }}
                            @else
                                {{ __('Expense') }}
                            @endif
                        </flux:heading>
                    @else
                        <flux:dropdown>
                            <flux:button variant="ghost" class="text-lg! font-semibold! {{ $typeColor }}" icon:trailing="chevron-down" type="button">
                                @if($transactionType === 'transfer')
                                    {{ __('transfer between accounts') }}
                                @elseif($transactionType === 'income')
                                    {{ __('income') }}
                                @else
                                    {{ __('expense') }}
                                @endif
                            </flux:button>

                            <flux:menu>
                                <flux:menu.item wire:click="$set('transactionType', 'expense')" class="text-red-600 dark:text-red-400">
                                    {{ __('expense') }}
                                </flux:menu.item>
                                <flux:menu.item wire:click="$set('transactionType', 'income')" class="text-green-600 dark:text-green-400">
                                    {{ __('income') }}
                                </flux:menu.item>
                                <flux:menu.item wire:click="$set('transactionType', 'transfer')" class="text-amber-600 dark:text-amber-400">
                                    {{ __('transfer between accounts') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endif

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
            </div>

            @if(!$editingTransactionId && !$editingPlannedTransactionId)
                <div class="flex items-center justify-center gap-4">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Enter vs Plan') }}</flux:text>
                    <div class="flex gap-1">
                        <flux:button
                                variant="{{ $mode === 'enter' ? 'filled' : 'ghost' }}"
                                size="sm"
                                wire:click="$set('mode', 'enter')"
                                type="button"
                                icon="check"
                        >
                            {{ __('Enter') }}
                        </flux:button>
                        <flux:button
                                variant="{{ $mode === 'plan' ? 'filled' : 'ghost' }}"
                                size="sm"
                                wire:click="$set('mode', 'plan')"
                                type="button"
                                icon="pencil"
                        >
                            {{ __('Plan') }}
                        </flux:button>
                    </div>
                </div>
            @endif

            <flux:input
                    wire:model.blur="descriptionInput"
                    :label="$mode === 'plan' ? __('Planned amount with description') : __('Amount with description')"
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

            <flux:select
                    wire:model="accountId"
                    :label="$transactionType === 'transfer' ? __('From account') : __('Account')"
                    required
                    :disabled="$isBasiqTransaction"
            >
                <flux:select.option value="">{{ __('Select account') }}</flux:select.option>
                @foreach($accounts as $account)
                    <flux:select.option value="{{ $account->id }}">
                        {{ $account->name }} ({{ $formatMoney($account->balance) }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if($transactionType === 'transfer')
                <flux:select wire:model="transferToAccountId" :label="__('To account')" required>
                    <flux:select.option value="">{{ __('Select account') }}</flux:select.option>
                    @foreach($accounts as $account)
                        <flux:select.option value="{{ $account->id }}">
                            {{ $account->name }} ({{ $formatMoney($account->balance) }})
                        </flux:select.option>
                    @endforeach
                </flux:select>
            @endif

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

            @if($mode === 'plan')
                <flux:select wire:model="frequency" :label="__('Frequency')" required>
                    @foreach(RecurrenceFrequency::cases() as $freq)
                        <flux:select.option value="{{ $freq->value }}">
                            {{ $freq->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <div class="space-y-3">
                    <div class="flex gap-1">
                        <flux:button
                                variant="{{ $untilType === 'always' ? 'filled' : 'ghost' }}"
                                size="sm"
                                wire:click="$set('untilType', 'always')"
                                type="button"
                        >
                            {{ __('Always') }}
                        </flux:button>
                        <flux:button
                                variant="{{ $untilType === 'until-date' ? 'filled' : 'ghost' }}"
                                size="sm"
                                wire:click="$set('untilType', 'until-date')"
                                type="button"
                        >
                            {{ __('Until date') }}
                        </flux:button>
                    </div>

                    @if($untilType === 'until-date')
                        <flux:input
                                wire:model="untilDate"
                                :label="__('Until date')"
                                type="date"
                                required
                        />
                    @endif
                </div>
            @endif

            <flux:textarea
                    wire:model="notes"
                    :label="__('Notes')"
                    :placeholder="__('Optional notes')"
                    rows="2"
            />

            <div class="flex">
                @if($editingPlannedTransactionId)
                    <flux:button
                            variant="danger"
                            wire:click="deletePlannedTransaction"
                            wire:confirm="{{ __('Are you sure you want to delete this planned transaction?') }}"
                            type="button"
                    >
                        {{ __('Delete') }}
                    </flux:button>
                @elseif($editingTransactionId && !$isBasiqTransaction)
                    <flux:button
                            variant="danger"
                            wire:click="deleteTransaction"
                            wire:confirm="{{ __('Are you sure you want to delete this transaction?') }}"
                            type="button"
                    >
                        {{ __('Delete') }}
                    </flux:button>
                @endif
                <flux:spacer/>
                <flux:button type="submit" variant="primary" class="{{ $buttonClasses }}">
                    @if($editingPlannedTransactionId)
                        @if($transactionType === 'transfer')
                            {{ __('Update planned transfer') }}
                        @elseif($transactionType === 'expense')
                            {{ __('Update planned expense') }}
                        @else
                            {{ __('Update planned income') }}
                        @endif
                    @elseif($editingTransactionId)
                        @if($transactionType === 'transfer')
                            {{ __('Update transfer') }}
                        @elseif($transactionType === 'expense')
                            {{ __('Update expense') }}
                        @else
                            {{ __('Update income') }}
                        @endif
                    @elseif($mode === 'plan')
                        @if($transactionType === 'transfer')
                            {{ __('Plan transfer') }}
                        @elseif($transactionType === 'expense')
                            {{ __('Plan expense') }}
                        @else
                            {{ __('Plan income') }}
                        @endif
                    @else
                        @if($transactionType === 'transfer')
                            {{ __('Enter transfer') }}
                        @elseif($transactionType === 'expense')
                            {{ __('Enter expense') }}
                        @else
                            {{ __('Enter income') }}
                        @endif
                    @endif
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
