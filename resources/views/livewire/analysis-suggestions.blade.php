@php
    use App\Casts\MoneyCast;
    use App\Enums\PayFrequency;
    use App\Enums\RecurrenceFrequency;
    use App\Enums\RuleActionType;
    use App\Enums\SuggestionType;
@endphp
<div wire:poll.30s>
    @if($suggestions->isNotEmpty())
        <div class="space-y-4">
            <flux:heading size="lg">Analysis Suggestions</flux:heading>

            @if($suggestions->has(SuggestionType::PrimaryAccount->value))
                @foreach($suggestions->get(SuggestionType::PrimaryAccount->value) as $suggestion)
                    <div wire:key="suggestion-{{ $suggestion->id }}" class="rule-suggest items-center">
                        <flux:icon.sparkles class="size-5 mt-0.5"/>
                        <div class="flex-1">
                            <div class="t">Primary Account Detected</div>
                            <div class="s">
                                We detected <strong>{{ $suggestion->payload['account_name'] }}</strong> as your primary account.
                            </div>
                            <div class="s">
                                Income: {{ MoneyCast::format($suggestion->payload['income_amount']) }}
                                {{ PayFrequency::from($suggestion->payload['income_frequency'])->label() }}
                            </div>
                        </div>
                        <x-suggestion-actions
                            accept-method="acceptPrimaryAccount"
                            :suggestion-id="$suggestion->id"
                        />

                    </div>
                @endforeach
            @endif

            @if($suggestions->has(SuggestionType::PayCycle->value))
                @foreach($suggestions->get(SuggestionType::PayCycle->value) as $suggestion)
                    <div wire:key="suggestion-{{ $suggestion->id }}" class="rule-suggest">
                        <flux:icon.sparkles class="size-5 mt-0.5"/>
                        <div class="flex-1">
                            <div class="t">Pay Cycle Detected</div>
                            <div class="s">You appear to be paid regularly. Confirm or adjust the details below.</div>

                            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <flux:field>
                                    <flux:label>Pay Amount</flux:label>
                                    <flux:input type="number" wire:model="payAmount" step="0.01" min="0" placeholder="0.00"/>
                                    <flux:error name="payAmount"/>
                                </flux:field>

                                <flux:field>
                                    <flux:label>Frequency</flux:label>
                                    <flux:select wire:model="payFrequency">
                                        <flux:select.option value="">Select...</flux:select.option>
                                        @foreach(PayFrequency::cases() as $freq)
                                            <flux:select.option value="{{ $freq->value }}">{{ $freq->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="payFrequency"/>
                                </flux:field>

                                <flux:field>
                                    <flux:label>Next Pay Date</flux:label>
                                    <flux:input type="date" wire:model="nextPayDate"/>
                                    <flux:error name="nextPayDate"/>
                                </flux:field>
                            </div>

                            <div class="mt-4">
                                <x-suggestion-actions
                                    accept-method="acceptPayCycle"
                                    :suggestion-id="$suggestion->id"
                                />
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif

            @if($suggestions->has(SuggestionType::RecurringTransaction->value))
                <x-cib.card>
                    <flux:heading>Recurring Transactions Detected</flux:heading>
                    <flux:text class="mt-1">We found patterns that look like recurring transactions.</flux:text>

                    <div class="mt-4 max-h-96 space-y-3 overflow-y-auto">
                        @foreach($suggestions->get(SuggestionType::RecurringTransaction->value) as $suggestion)
                            <div class="day-card" wire:key="suggestion-{{ $suggestion->id }}">
                                <x-cib.tx-row
                                    :transaction-id="null"
                                    :planned-transaction-id="null"
                                    :name="$suggestion->payload['clean_description']"
                                    :amount="abs($suggestion->payload['amount'])"
                                    :tone="$suggestion->payload['direction'] === 'debit' ? 'out' : 'inc'"
                                >
                                    <x-slot:meta>
                                        {{ RecurrenceFrequency::from($suggestion->payload['frequency'])->label() }}
                                        · {{ count($suggestion->payload['matched_transaction_ids']) }} matches
                                    </x-slot:meta>
                                    <x-slot:actions>
                                        <x-category-combobox
                                            wire:model="recurringCategories.{{ $suggestion->id }}"
                                            :categories="$categories"
                                            placeholder="No category"
                                            size="sm"
                                            class="w-40"
                                        />

                                        <x-suggestion-actions
                                            accept-method="acceptRecurringTransaction"
                                            :suggestion-id="$suggestion->id"
                                        />
                                    </x-slot:actions>
                                </x-cib.tx-row>
                            </div>
                        @endforeach
                    </div>
                </x-cib.card>
            @endif

            @if($suggestions->has(SuggestionType::UserRule->value))
                <x-cib.card>
                    <flux:heading>Rule Matches</flux:heading>
                    <flux:text class="mt-1">Your rules matched the following transactions.</flux:text>

                    <div class="mt-4 max-h-96 space-y-3 overflow-y-auto">
                        @foreach($suggestions->get(SuggestionType::UserRule->value) as $suggestion)
                            <div class="day-card" wire:key="suggestion-{{ $suggestion->id }}">
                                <x-cib.tx-row
                                    :transaction-id="null"
                                    :planned-transaction-id="null"
                                    :name="$suggestion->payload['rule_name']"
                                    :amount="0"
                                    tone="out"
                                    class="[&_.tx-amt]:invisible"
                                >
                                    <x-slot:meta>
                                        <div class="flex flex-wrap items-center gap-1">
                                            @foreach($suggestion->payload['actions'] as $action)
                                                <flux:badge size="sm" color="purple">
                                                    {{ RuleActionType::from($action['type'])->label() }}
                                                </flux:badge>
                                            @endforeach
                                        </div>
                                        @if(isset($ruleTransactionDescriptions[$suggestion->payload['transaction_id']]))
                                            <div class="mt-1 text-zinc-500">
                                                {{ $ruleTransactionDescriptions[$suggestion->payload['transaction_id']] }}
                                            </div>
                                        @endif
                                    </x-slot:meta>
                                    <x-slot:actions>
                                        <x-suggestion-actions
                                            accept-method="acceptUserRule"
                                            :suggestion-id="$suggestion->id"
                                        />
                                    </x-slot:actions>
                                </x-cib.tx-row>
                            </div>
                        @endforeach
                    </div>
                </x-cib.card>
            @endif
        </div>
    @endif
</div>
