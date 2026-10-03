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
                <div class="border-l-4 pl-3 {{ match ($transactionType) { 'income' => 'border-green-500', 'transfer' => 'border-orange-500', default => 'border-red-500' } }}">
                    <flux:heading size="lg" class="font-black">
                        @if($editingPlannedTransactionId)
                            {{ __('Edit planned') }}
                        @elseif($transactionType === 'transfer')
                            {{ __('Between Accounts') }}
                        @elseif($editingTransactionId)
                            {{ __('Edit transaction') }}
                        @else
                            {{ __('Add transaction') }}
                        @endif
                    </flux:heading>
                    @if($isBankFeedTransaction)
                        <flux:badge color="blue" size="sm" icon="cloud-arrow-down" class="mt-1">
                            {{ __('Synced from bank') }}
                        </flux:badge>
                    @endif
                    @php
                        $typeTone = match ($transactionType) {
                            'income' => [
                                'select' => 'text-green-700! border-green-500! bg-green-50!',
                                'header' => 'border-green-500',
                                'button' => 'bg-green-600!',
                            ],
                            'transfer' => [
                                'select' => 'text-orange-700! border-orange-500! bg-orange-50!',
                                'header' => 'border-orange-500',
                                'button' => 'bg-orange-600!',
                            ],
                            default => [
                                'select' => 'text-red-700! border-red-500! bg-red-50!',
                                'header' => 'border-red-500',
                                'button' => 'bg-red-600!',
                            ],
                        };
                        // A bank-feed row's direction is the bank's, so it can only be that
                        // direction's type or a transfer.
                        $feedDirection = $isBankFeedTransaction ? $bankFeedTransactionDirection : null;
                    @endphp
                    <flux:select
                        wire:model.live="transactionType"
                        :aria-label="__('Transaction type')"
                        class="mt-2 font-bold {{ $typeTone['select'] }}"
                        data-testid="transaction-type"
                    >
                        @if($feedDirection !== 'credit')
                            <flux:select.option value="expense">{{ __('Expense') }}</flux:select.option>
                        @endif
                        @if($feedDirection !== 'debit')
                            <flux:select.option value="income">{{ __('Income') }}</flux:select.option>
                        @endif
                        <flux:select.option value="transfer">{{ __('Transfer between accounts') }}</flux:select.option>
                    </flux:select>
                </div>

                <div>
                    @if($date && $isBankFeedTransaction)
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

            {{-- Enter vs Plan toggle --}}
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

            {{-- Description / amount-with-description input --}}
            @if($transactionType === 'transfer')
                <flux:input
                    wire:key="description-transfer"
                    wire:model.blur="descriptionInput"
                    :label="$mode === 'plan' ? __('Planned amount with description') : __('Actual amount with description')"
                    placeholder="100 savings transfer"
                    required
                    :disabled="$isBankFeedTransaction"
                />
            @else
                <flux:textarea
                    wire:key="description-non-transfer"
                    wire:model.blur="descriptionInput"
                    :label="$mode === 'plan' ? __('Planned amount with description') : __('Actual amount with description')"
                    placeholder="4*15 zoo tickets&#10;(100 in parentheses is ignored)"
                    rows="2"
                    required
                    :disabled="$isBankFeedTransaction"
                />
            @endif

            @if($isBankFeedTransaction)
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

            {{-- Account selection. A bank-feed row's own account is locked; on an unlinked
                 credit (deposit) it is the receiving side, so the picker below chooses "From". --}}
            @php
                $incomingFeedTransfer = $isBankFeedTransaction && ! $originalWasTransfer && $bankFeedTransactionDirection === 'credit';
                $ownAccountLabel = $transactionType !== 'transfer'
                    ? __('Account')
                    : ($incomingFeedTransfer ? __('To account') : __('From account'));
            @endphp
            <flux:select
                wire:model="accountId"
                :label="$ownAccountLabel"
                required
                :disabled="$isBankFeedTransaction"
            >
                <flux:select.option value="">{{ __('Select account') }}</flux:select.option>
                @foreach($fromAccounts as $account)
                    <flux:select.option value="{{ $account->id }}">
                        {{ $account->name }} ({{ $formatMoney($account->balance) }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if($isBankFeedTransaction && $this->isSuggestedTransfer())
                <div class="flex items-center justify-between gap-2 rounded-md bg-cib-cream-50 px-3 py-2">
                    <flux:text size="sm" class="font-bold" data-testid="suggested-transfer-note">{{ __('Possible transfer') }}</flux:text>
                    <div class="flex gap-2">
                        <flux:button type="button" size="sm" wire:click="confirmSuggestedTransfer">{{ __('Confirm') }}</flux:button>
                        <flux:button type="button" size="sm" variant="ghost" wire:click="rejectSuggestedTransfer">{{ __('Not a transfer') }}</flux:button>
                    </div>
                </div>
            @endif

            @if($transactionType === 'transfer')
                @if(! $isBankFeedTransaction)
                    <div class="flex justify-center">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="ghost"
                            icon="arrows-up-down"
                            wire:click="swapTransferAccounts"
                            :aria-label="__('Swap accounts')"
                            data-testid="swap-transfer-accounts"
                        />
                    </div>
                @endif
                @php
                    $bankFeedUnlinkedTransfer = $isBankFeedTransaction && ! $originalWasTransfer;
                    $trackedAccounts = $accounts->where('is_tracked', true);
                    $untrackedAccounts = $accounts->where('is_tracked', false);
                    $counterpartLabel = match (true) {
                        $bankFeedUnlinkedTransfer && $incomingFeedTransfer => __('From account (optional)'),
                        $bankFeedUnlinkedTransfer => __('To account (optional)'),
                        default => __('To account'),
                    };
                @endphp
                <flux:select
                    wire:model.live="transferToAccountId"
                    :label="$counterpartLabel"
                    :required="! $bankFeedUnlinkedTransfer"
                    :disabled="$isBankFeedTransaction && $originalWasTransfer"
                    data-testid="transfer-to-account"
                >
                    <flux:select.option value="">{{ __('Select account') }}</flux:select.option>
                    @foreach($trackedAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">
                            {{ $account->name }} ({{ $formatMoney($account->balance) }})
                        </flux:select.option>
                    @endforeach
                    @if($untrackedAccounts->isNotEmpty() && ! ($bankFeedUnlinkedTransfer && $this->hasOppositeRows()))
                        <optgroup label="{{ __('Hidden (untracked)') }}">
                            @foreach($untrackedAccounts as $account)
                                <flux:select.option value="{{ $account->id }}">
                                    {{ $account->name }} ({{ $formatMoney($account->balance) }})
                                </flux:select.option>
                            @endforeach
                        </optgroup>
                    @endif
                </flux:select>

                @if($bankFeedUnlinkedTransfer)
                    @php($candidates = $this->transferCandidates())
                    <div class="rounded-md border-2 border-cib-black bg-cib-cream-50 px-4 py-3 space-y-2" data-testid="transfer-candidates">
                        @if($candidates->isEmpty())
                            <flux:text size="sm">
                                {{ __('No matching transaction found to link. Pick a hidden (untracked) account above to record the other side.') }}
                            </flux:text>
                        @else
                            <flux:text size="sm" class="font-bold">
                                {{ $candidates->count() === 1 ? __('This will link to the matching transaction:') : __('Several transactions match — choose the other side:') }}
                            </flux:text>
                            @foreach($candidates as $candidate)
                                <label class="flex items-start gap-2 text-sm">
                                    @if($candidates->count() > 1)
                                        <input type="radio" wire:model="selectedCandidateId" value="{{ $candidate->id }}" class="mt-1"/>
                                    @endif
                                    <span>
                                        {{ $candidate->account?->name }} · {{ $candidate->post_date->format('D j M Y') }} · {{ $candidate->description }}
                                    </span>
                                </label>
                            @endforeach
                            @error('selectedCandidateId')
                                <p class="text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        @endif
                    </div>
                @endif

                @if($isBankFeedTransaction && $originalWasTransfer)
                    <div class="flex items-center justify-between gap-2 rounded-md bg-cib-cream-50 px-3 py-2">
                        <flux:text size="sm">{{ __('Linked transfer') }}</flux:text>
                        <flux:button type="button" size="sm" variant="ghost" wire:click="unlinkTransfer">{{ __('Unlink transfer') }}</flux:button>
                    </div>
                @endif
            @endif

            <x-category-combobox
                wire:model="categoryId"
                :categories="$categories"
                :label="__('Category')"
                :placeholder="__('No category')"
            />

            @if($editingTransactionId && $transactionType !== 'transfer')
                <flux:field variant="inline">
                    <flux:checkbox wire:model.live="categoriseMatching"/>
                    <flux:label>{{ __('Also categorise matching transactions') }}</flux:label>
                    <flux:description>{{ __('Creates a rule that applies this category to past and future transactions from the same merchant.') }}</flux:description>
                </flux:field>

                @if($categoriseMatching)
                    <flux:input
                        wire:model.live.debounce.400ms="categoriseMatchValue"
                        :label="__('Match when description contains')"
                        :description="__('Edit to control which transactions are categorised — keep it specific to this merchant.')"
                        data-testid="transaction-categorise-match-value"
                    />

                    @if($categoriseContradictions !== [])
                        @php($contradictingCount = array_sum($categoriseContradictions))
                        <p class="text-sm text-red-700 dark:text-red-400" data-testid="categorise-contradicts">
                            {{ trans_choice(':count transaction you categorised yourself is filed differently:|:count transactions you categorised yourself are filed differently:', $contradictingCount, ['count' => $contradictingCount]) }}
                            @foreach($categoriseContradictions as $path => $count)
                                {{ $path }} ({{ $count }}){{ ! $loop->last ? ', ' : '' }}
                            @endforeach
                        </p>

                        {{-- Native disclosure: flux:accordion is a Pro component. wire:ignore.self keeps it open across re-renders. --}}
                        <details class="text-sm" wire:ignore.self data-testid="categorise-contradicts-list">
                            <summary class="cursor-pointer text-zinc-600 dark:text-zinc-400">
                                {{ trans_choice('Show the transaction|Show the :count transactions', $contradictingCount, ['count' => $contradictingCount]) }}
                            </summary>
                            <ul class="mt-2 max-h-48 space-y-2 overflow-y-auto">
                                @foreach($categoriseContradictionRows as $row)
                                    <li wire:key="categorise-contradiction-{{ $row['id'] }}">
                                        <span class="font-medium break-words">{{ $row['description'] }}</span>
                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $row['date'] }} · {{ $row['category'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </details>

                        {{-- The tick submits the current list's key, so a tick given for an earlier list renders unticked and moves nothing. Keyed by that list because Flux reads a checkbox's value only once, on init. --}}
                        <flux:checkbox.group wire:model="categoriseMoveConsent" wire:key="categorise-move-{{ $categoriseContradictionKey }}">
                            <flux:checkbox
                                value="{{ $categoriseContradictionKey }}"
                                :label="trans_choice('Also update this transaction to the new category|Also update these :count transactions to the new category', $contradictingCount, ['count' => $contradictingCount])"
                                :description="__('Otherwise the rule leaves them as they are.')"
                                data-testid="categorise-move-contradictions"
                            />
                        </flux:checkbox.group>
                        <flux:error name="categoriseMoveConsent"/>
                    @endif
                @endif
            @endif

            {{-- Plan-mode fields --}}
            @if($mode === 'plan')
                <flux:input
                    wire:model="date"
                    :label="__('Date')"
                    type="date"
                    required
                />

                <flux:select wire:model.live="frequency" :label="__('Frequency')" required>
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

            {{-- Transfer / bank-feed notes, plus any existing notes (e.g. folded intl-fee note) --}}
            @if($transactionType === 'transfer' || $isBankFeedTransaction || $notes !== '')
                <flux:textarea
                    wire:model="notes"
                    :label="$transactionType === 'transfer' ? __('Transfer description') : __('Notes')"
                    :placeholder="__('Optional notes')"
                    rows="2"
                />
            @endif

            {{-- Rule-suggest card (plan-mode, non-transfer, manual) --}}
            @if(!$isBankFeedTransaction && $mode === 'plan' && $transactionType !== 'transfer')
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
                @elseif($editingTransactionId && !$isBankFeedTransaction)
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
                    class="{{ $typeTone['button'] }} border-2! border-cib-black! text-white! shadow-pop!"
                    data-testid="transaction-submit"
                >
                    @if($editingTransactionId && $mode === 'plan' && !$isBankFeedTransaction)
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
                    @elseif($this->isRealizableOccurrence())
                        @if($transactionType === 'transfer')
                            {{ __('Enter transfer') }}
                        @elseif($transactionType === 'expense')
                            {{ __('Enter expense') }}
                        @else
                            {{ __('Enter income') }}
                        @endif
                    @elseif($editingPlannedTransactionId)
                        @if($transactionType === 'transfer')
                            {{ __('Update planned transfer') }}
                        @elseif($transactionType === 'expense')
                            {{ __('Update planned expense') }}
                        @else
                            {{ __('Update planned income') }}
                        @endif
                    @elseif($editingTransactionId && $mode !== 'plan')
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
