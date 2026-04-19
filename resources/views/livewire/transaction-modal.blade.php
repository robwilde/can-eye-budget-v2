@php
    use App\Enums\RecurrenceFrequency;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Str;
@endphp
<div>
    <flux:modal wire:model="showModal" class="md:w-lg rounded-t-xl border-t-2 border-cib-black">
        <form wire:submit="save" class="space-y-5">
            {{-- Header: title + date --}}
            <div class="flex items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg" class="font-black">
                        @if($editingPlannedTransactionId)
                            {{ __('Edit planned') }}
                        @elseif($editingTransactionId)
                            {{ __('Edit transaction') }}
                        @else
                            {{ __('Add transaction') }}
                        @endif
                    </flux:heading>
                    @if($isBasiqTransaction)
                        <flux:badge color="blue" size="sm" icon="cloud-arrow-down" class="mt-1">
                            {{ __('Synced from bank') }}
                        </flux:badge>
                    @endif
                </div>

                <div>
                    @if($date && $isBasiqTransaction)
                        <flux:badge color="zinc">
                            {{ CarbonImmutable::parse($date)->format('D j M Y') }}
                        </flux:badge>
                    @elseif($date)
                        <flux:input
                            type="date"
                            wire:model.live="date"
                            class="py-1! text-sm!"
                        />
                    @endif
                </div>
            </div>

            {{-- Type toggle: three-pill segmented control (manual only) --}}
            @if(!$isBasiqTransaction)
                <div class="type-toggle" role="group" aria-label="{{ __('Transaction type') }}">
                    <button type="button"
                            aria-pressed="{{ $transactionType === 'expense' ? 'true' : 'false' }}"
                            class="{{ $transactionType === 'expense' ? 'active out' : '' }}"
                            wire:click="$set('transactionType', 'expense')">
                        {{ __('Expense') }}
                    </button>
                    <button type="button"
                            aria-pressed="{{ $transactionType === 'income' ? 'true' : 'false' }}"
                            class="{{ $transactionType === 'income' ? 'active inc' : '' }}"
                            wire:click="$set('transactionType', 'income')">
                        {{ __('Income') }}
                    </button>
                    <button type="button"
                            aria-pressed="{{ $transactionType === 'transfer' ? 'true' : 'false' }}"
                            class="{{ $transactionType === 'transfer' ? 'active xfr' : '' }}"
                            wire:click="$set('transactionType', 'transfer')">
                        {{ __('Transfer') }}
                    </button>
                </div>
            @else
                <flux:heading class="font-black">
                    @if($transactionType === 'transfer')
                        {{ __('Between Accounts') }}
                    @elseif($transactionType === 'income')
                        {{ __('Income') }}
                    @else
                        {{ __('Expense') }}
                    @endif
                </flux:heading>
            @endif

            {{-- Enter vs Plan toggle (manual only) --}}
            @if(!$isBasiqTransaction)
                <div class="type-toggle" role="group" aria-label="{{ __('Enter vs Plan') }}">
                    <button type="button"
                            aria-pressed="{{ $mode === 'enter' ? 'true' : 'false' }}"
                            class="{{ $mode === 'enter' ? 'active' : '' }}"
                            wire:click="$set('mode', 'enter')">
                        {{ __('Enter') }}
                    </button>
                    <button type="button"
                            aria-pressed="{{ $mode === 'plan' ? 'true' : 'false' }}"
                            class="{{ $mode === 'plan' ? 'active' : '' }}"
                            wire:click="$set('mode', 'plan')">
                        {{ __('Plan') }}
                    </button>
                </div>
            @endif

            {{-- Description / amount-with-description input --}}
            @if($transactionType === 'transfer')
                <flux:input
                    wire:key="description-transfer"
                    wire:model.blur="descriptionInput"
                    :label="$mode === 'plan' ? __('Planned amount with description') : __('Actual amount with description')"
                    placeholder="100 savings transfer"
                    required
                    :disabled="$isBasiqTransaction"
                />
            @else
                <flux:textarea
                    wire:key="description-non-transfer"
                    wire:model.blur="descriptionInput"
                    :label="$mode === 'plan' ? __('Planned amount with description') : __('Actual amount with description')"
                    placeholder="4*15 zoo tickets&#10;(100 in parentheses is ignored)"
                    rows="2"
                    required
                    :disabled="$isBasiqTransaction"
                />
            @endif

            @if($isBasiqTransaction)
                <flux:input
                    wire:model.blur="cleanDescription"
                    :label="__('Clean description')"
                    :placeholder="__('Your description for this transaction')"
                />
            @endif

            {{-- Parsed amount card --}}
            <div class="rounded-md border-2 border-cib-black bg-cib-cream-50 px-4 py-3 shadow-pop-sm">
                <flux:text size="sm" class="font-bold uppercase tracking-wider text-cib-n-600">
                    {{ __('Parsed amount') }}
                </flux:text>
                <div class="mt-1 text-2xl font-black tabular-nums text-cib-black">
                    {{ $formatMoney($parsedAmount) }}
                </div>
            </div>

            {{-- Account selection --}}
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

            {{-- Category chip grid --}}
            <div>
                <label class="cib-label">{{ __('Category') }}</label>
                <div class="grid grid-cols-4 gap-2 max-h-80 overflow-y-auto pr-1">
                    <button type="button"
                            class="cat-chip"
                            aria-pressed="{{ $categoryId === null ? 'true' : 'false' }}"
                            wire:click="$set('categoryId', null)">
                        <span class="cat-color inline-grid h-5 w-5 place-items-center rounded-full bg-cib-n-100 font-black">
                            &nbsp;
                        </span>
                        <span class="truncate">{{ __('Uncategorised') }}</span>
                    </button>
                    @foreach($categories as $c)
                        <button type="button"
                                class="cat-chip"
                                aria-pressed="{{ $categoryId === $c->id ? 'true' : 'false' }}"
                                wire:click="$set('categoryId', {{ $c->id }})">
                            @if($c->icon)
                                <flux:icon name="{{ $c->icon }}" class="cat-color" />
                            @else
                                <span class="cat-color inline-grid h-5 w-5 place-items-center rounded-full bg-cib-n-100 font-black text-xs">
                                    {{ Str::upper(Str::substr($c->name, 0, 1)) }}
                                </span>
                            @endif
                            <span class="truncate w-full">{{ $c->name }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Plan-mode fields --}}
            @if($mode === 'plan')
                <flux:input
                    wire:model="date"
                    :label="__('Date')"
                    type="date"
                    required
                    :disabled="$isBasiqTransaction"
                />

                <flux:select wire:model="frequency" :label="__('Frequency')" required>
                    @foreach(RecurrenceFrequency::cases() as $freq)
                        <flux:select.option value="{{ $freq->value }}">
                            {{ $freq->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <div class="space-y-3">
                    <div class="type-toggle" role="group" aria-label="{{ __('Repeat until') }}">
                        <button type="button"
                                aria-pressed="{{ $untilType === 'always' ? 'true' : 'false' }}"
                                class="{{ $untilType === 'always' ? 'active' : '' }}"
                                wire:click="$set('untilType', 'always')">
                            {{ __('Always') }}
                        </button>
                        <button type="button"
                                aria-pressed="{{ $untilType === 'until-date' ? 'true' : 'false' }}"
                                class="{{ $untilType === 'until-date' ? 'active' : '' }}"
                                wire:click="$set('untilType', 'until-date')">
                            {{ __('Until date') }}
                        </button>
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

            {{-- Transfer / basiq notes --}}
            @if($transactionType === 'transfer' || $isBasiqTransaction)
                <flux:textarea
                    wire:model="notes"
                    :label="$transactionType === 'transfer' ? __('Transfer description') : __('Notes')"
                    :placeholder="__('Optional notes')"
                    rows="2"
                />
            @endif

            {{-- Rule-suggest card (plan-mode, non-transfer, manual) --}}
            @if(!$isBasiqTransaction && $mode === 'plan' && $transactionType !== 'transfer')
                <div class="rule-suggest" role="complementary">
                    <flux:icon name="sparkles" class="mt-0.5 shrink-0" />
                    <div>
                        <div class="t">{{ __('Make this a rule?') }}</div>
                        <div class="s">{{ __('Auto-apply category, amount and tag next time a matching transaction appears.') }}</div>
                        {{-- TODO #196-followup: wire to UserRuleManager or a dedicated RuleFromTransactionModal --}}
                        <button type="button" class="link" wire:click="$dispatch('open-rule-from-transaction')">
                            {{ __('Set up rule') }}
                        </button>
                    </div>
                </div>
            @endif

            {{-- Sticky footer --}}
            <div class="modal-foot">
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
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    type="submit"
                    variant="primary"
                    class="bg-cib-yellow-400! text-cib-black! border-2! border-cib-black! shadow-pop!"
                >
                    @if($editingTransactionId && $mode === 'plan')
                        @if($transactionType === 'transfer')
                            {{ __('Convert to planned transfer') }}
                        @elseif($transactionType === 'expense')
                            {{ __('Convert to planned expense') }}
                        @else
                            {{ __('Convert to planned income') }}
                        @endif
                    @elseif($editingPlannedTransactionId && $mode === 'enter')
                        @if($transactionType === 'transfer')
                            {{ __('Convert to entered transfer') }}
                        @elseif($transactionType === 'expense')
                            {{ __('Convert to entered expense') }}
                        @else
                            {{ __('Convert to entered income') }}
                        @endif
                    @elseif($editingPlannedTransactionId)
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
