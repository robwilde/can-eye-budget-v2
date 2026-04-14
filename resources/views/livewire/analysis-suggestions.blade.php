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
                    <flux:card wire:key="suggestion-{{ $suggestion->id }}">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <flux:heading>Primary Account Detected</flux:heading>
                                <flux:text class="mt-1">
                                    We detected <strong>{{ $suggestion->payload['account_name'] }}</strong> as your primary account.
                                </flux:text>
                                <flux:text size="sm" class="mt-1 text-zinc-500">
                                    Income: {{ MoneyCast::format($suggestion->payload['income_amount']) }}
                                    {{ PayFrequency::from($suggestion->payload['income_frequency'])->label() }}
                                </flux:text>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <flux:button
                                    variant="primary"
                                    size="sm"
                                    wire:click="acceptPrimaryAccount({{ $suggestion->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="acceptPrimaryAccount({{ $suggestion->id }})"
                                >
                                    <flux:icon.loading wire:loading wire:target="acceptPrimaryAccount({{ $suggestion->id }})" class="size-4"/>
                                    Accept
                                </flux:button>
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    wire:click="rejectSuggestion({{ $suggestion->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="rejectSuggestion({{ $suggestion->id }})"
                                >
                                    Dismiss
                                </flux:button>
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            @endif

            @if($suggestions->has(SuggestionType::PayCycle->value))
                @foreach($suggestions->get(SuggestionType::PayCycle->value) as $suggestion)
                    <flux:card wire:key="suggestion-{{ $suggestion->id }}">
                        <flux:heading>Pay Cycle Detected</flux:heading>
                        <flux:text class="mt-1">You appear to be paid regularly. Confirm or adjust the details below.</flux:text>

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

                        <div class="mt-4 flex items-center gap-2">
                            <flux:button
                                variant="primary"
                                size="sm"
                                wire:click="acceptPayCycle({{ $suggestion->id }})"
                                wire:loading.attr="disabled"
                                wire:target="acceptPayCycle({{ $suggestion->id }})"
                            >
                                <flux:icon.loading wire:loading wire:target="acceptPayCycle({{ $suggestion->id }})" class="size-4"/>
                                Accept
                            </flux:button>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                wire:click="rejectSuggestion({{ $suggestion->id }})"
                                wire:loading.attr="disabled"
                                wire:target="rejectSuggestion({{ $suggestion->id }})"
                            >
                                Dismiss
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            @endif

            @if($suggestions->has(SuggestionType::RecurringTransaction->value))
                <flux:card>
                    <flux:heading>Recurring Transactions Detected</flux:heading>
                    <flux:text class="mt-1">We found patterns that look like recurring transactions.</flux:text>

                    <div class="mt-4 max-h-96 space-y-3 overflow-y-auto">
                        @foreach($suggestions->get(SuggestionType::RecurringTransaction->value) as $suggestion)
                            <div wire:key="suggestion-{{ $suggestion->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="truncate font-medium">{{ $suggestion->payload['clean_description'] }}</flux:text>
                                        <flux:badge size="sm" :color="$suggestion->payload['direction'] === 'debit' ? 'red' : 'green'">
                                            {{ MoneyCast::format(abs($suggestion->payload['amount'])) }}
                                        </flux:badge>
                                    </div>
                                    <div class="mt-1 flex items-center gap-3">
                                        <flux:text size="sm" class="text-zinc-500">
                                            {{ RecurrenceFrequency::from($suggestion->payload['frequency'])->label() }}
                                        </flux:text>
                                        <flux:text size="sm" class="text-zinc-500">
                                            {{ count($suggestion->payload['matched_transaction_ids']) }} matches
                                        </flux:text>
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <x-category-combobox
                                        wire:model="recurringCategories.{{ $suggestion->id }}"
                                        :categories="$categories"
                                        placeholder="No category"
                                        size="sm"
                                        class="w-40"
                                    />

                                    <flux:button
                                        variant="primary"
                                        size="sm"
                                        wire:click="acceptRecurringTransaction({{ $suggestion->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="acceptRecurringTransaction({{ $suggestion->id }})"
                                    >
                                        <flux:icon.loading wire:loading wire:target="acceptRecurringTransaction({{ $suggestion->id }})" class="size-4"/>
                                        Accept
                                    </flux:button>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        wire:click="rejectSuggestion({{ $suggestion->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="rejectSuggestion({{ $suggestion->id }})"
                                    >
                                        Dismiss
                                    </flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @endif

            @if($suggestions->has(SuggestionType::UserRule->value))
                <flux:card>
                    <flux:heading>Rule Matches</flux:heading>
                    <flux:text class="mt-1">Your rules matched the following transactions.</flux:text>

                    <div class="mt-4 max-h-96 space-y-3 overflow-y-auto">
                        @foreach($suggestions->get(SuggestionType::UserRule->value) as $suggestion)
                            <div wire:key="suggestion-{{ $suggestion->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="truncate font-medium">{{ $suggestion->payload['rule_name'] }}</flux:text>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @foreach($suggestion->payload['actions'] as $action)
                                            <flux:badge size="sm" color="purple">
                                                {{ RuleActionType::from($action['type'])->label() }}
                                            </flux:badge>
                                        @endforeach
                                    </div>
                                    @if(isset($ruleTransactionDescriptions[$suggestion->payload['transaction_id']]))
                                        <flux:text size="sm" class="mt-1 text-zinc-500">
                                            {{ $ruleTransactionDescriptions[$suggestion->payload['transaction_id']] }}
                                        </flux:text>
                                    @endif
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <flux:button
                                        variant="primary"
                                        size="sm"
                                        wire:click="acceptUserRule({{ $suggestion->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="acceptUserRule({{ $suggestion->id }})"
                                    >
                                        <flux:icon.loading wire:loading wire:target="acceptUserRule({{ $suggestion->id }})" class="size-4"/>
                                        Accept
                                    </flux:button>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        wire:click="rejectSuggestion({{ $suggestion->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="rejectSuggestion({{ $suggestion->id }})"
                                    >
                                        Dismiss
                                    </flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @endif
        </div>
    @endif
</div>
