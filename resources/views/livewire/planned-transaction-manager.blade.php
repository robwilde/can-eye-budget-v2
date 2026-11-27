@php
    use App\Casts\MoneyCast;
    use App\Enums\RecurrenceFrequency;
    use App\Rules\PaymentUrl;
    use Illuminate\Support\Str;
@endphp

<flux:card>
    <div class="space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="lg">Planned transactions</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">Manage the recurring plans that match future imports for this account.</flux:text>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <flux:button size="sm" variant="primary" wire:click="$dispatch('declare-plan', { kind: 'bill' })" data-testid="add-bill" :disabled="! $recurringOn">Add bill</flux:button>
                <flux:button size="sm" wire:click="$dispatch('declare-plan', { kind: 'income' })" data-testid="add-income" :disabled="! $recurringOn">Add income</flux:button>
            </div>

            @unless($recurringOn)
                <flux:callout variant="warning" icon="exclamation-triangle" class="w-full" data-testid="recurring-off-notice">
                    <flux:callout.text>{{ __('Recurring is switched off. Switch it on in Settings → Recurring.') }}</flux:callout.text>
                </flux:callout>
            @endunless

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
        </div>

        @if($plans->isNotEmpty())
            <div class="overflow-x-auto rounded-lg border border-neutral-200">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-neutral-200 text-left text-zinc-500">
                            <th class="px-4 py-3 font-medium">Description</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 text-right font-medium">Amount</th>
                            <th class="px-4 py-3 font-medium">Direction</th>
                            <th class="px-4 py-3 font-medium">Frequency</th>
                            <th class="px-4 py-3 font-medium">Category</th>
                            <th class="px-4 py-3 font-medium">Matches</th>
                            <th class="px-4 py-3 font-medium">Next occurrence</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach($plans as $plan)
                            <tr wire:key="planned-transaction-{{ $plan->id }}">
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:text class="font-medium text-zinc-900">{{ $plan->description }}</flux:text>
                                        @unless($plan->isActive())
                                            <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                        @endunless
                                        @if($plan->is_pay_cycle_income)
                                            <flux:badge size="sm" color="green">Income</flux:badge>
                                        @endif
                                        @if($plan->autopay)
                                            <flux:badge size="sm" color="zinc">Autopay</flux:badge>
                                        @endif
                                    </div>
                                    @if(PaymentUrl::isSafe($plan->payment_url))
                                        <a href="{{ $plan->payment_url }}" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-700 underline" data-testid="plan-payment-link">Pay online</a>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text data-testid="plan-type">{{ $plan->type->label() }}</flux:text>
                                </td>
                                <td class="px-4 py-3 text-right align-top">
                                    <flux:text class="tabular-nums font-medium text-zinc-900">{{ MoneyCast::format($plan->amount) }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text>{{ Str::headline($plan->direction->value) }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text>{{ $plan->frequencyLabel() }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text>{{ $plan->category?->fullPath() ?? 'No category' }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:badge size="sm" color="purple">{{ $plan->matched_count }} {{ Str::plural('match', (int) $plan->matched_count) }}</flux:badge>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <flux:text>{{ $plan->next_occurrence?->format('Y-m-d') ?? '—' }}</flux:text>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-2">
                                        @if($plan->is_pay_cycle_income)
                                            <flux:text size="sm" class="text-zinc-500">Managed from pay cycle</flux:text>
                                        @else
                                            <flux:button variant="ghost" size="sm" wire:click="openEdit({{ $plan->id }})" :disabled="! $recurringOn">
                                                <flux:icon.pencil class="size-4" />
                                            </flux:button>
                                            <flux:button variant="ghost" size="sm" wire:click="$set('occurrencesPlanId', {{ $occurrencesPlanId === $plan->id ? 'null' : $plan->id }})" data-testid="plan-occurrences-toggle-{{ $plan->id }}">
                                                {{ __('Occurrences') }}
                                            </flux:button>
                                            @if($smartFillAvailable)
                                                <flux:button variant="ghost" size="sm" wire:click="smartConfigure({{ $plan->id }})" wire:loading.attr="disabled" wire:target="smartConfigure" data-testid="plan-smart-configure-{{ $plan->id }}">
                                                    {{ __('Smart configure') }}
                                                </flux:button>
                                            @endif
                                            <flux:button variant="ghost" size="sm" wire:click="toggleActive({{ $plan->id }})" data-testid="plan-toggle-{{ $plan->id }}" :disabled="! $recurringOn">
                                                {{ $plan->isActive() ? 'Deactivate' : 'Activate' }}
                                            </flux:button>
                                            <flux:button variant="ghost" size="sm" wire:click="confirmDelete({{ $plan->id }})" data-testid="plan-delete-{{ $plan->id }}" :disabled="! $recurringOn">
                                                <flux:icon.trash class="size-4" />
                                            </flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if($smartConfigurePlanId === $plan->id)
                                <tr wire:key="planned-transaction-{{ $plan->id }}-smart-configure" data-testid="plan-smart-configure-result">
                                    <td colspan="9" class="bg-neutral-50 px-4 py-3">
                                        <div role="status" aria-live="polite">
                                            <flux:error name="smartConfigure" />
                                            @if($smartConfigureNotice !== '')
                                                <flux:text size="sm" class="text-zinc-600" data-testid="plan-smart-configure-notice">{{ $smartConfigureNotice }}</flux:text>
                                            @endif
                                        </div>
                                        @foreach($smartConfigureChanges as $change)
                                            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 py-2 text-sm" data-testid="plan-smart-configure-change">
                                                <span class="w-32 font-medium">{{ $change['field'] }}</span>
                                                <span class="text-zinc-500"><span class="sr-only">{{ __('Now:') }}</span> {{ $change['current'] }}</span>
                                                <span aria-hidden="true">→</span>
                                                <span><span class="sr-only">{{ __('Suggested:') }}</span> {{ $change['suggested'] }}</span>
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endif
                            @if($occurrencesPlanId === $plan->id)
                                <tr wire:key="planned-transaction-{{ $plan->id }}-occurrences" data-testid="plan-occurrences">
                                    <td colspan="9" class="bg-neutral-50 px-4 py-3">
                                        @error('occurrence')
                                            <p class="mb-2 text-sm text-red-700" data-testid="occurrence-error" role="alert">{{ $message }}</p>
                                        @enderror
                                        @forelse($occurrences as $occurrence)
                                            @php($state = $occurrenceStates->resolve($occurrence, $today))
                                            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 py-2 text-sm" wire:key="occurrence-{{ $occurrence->id }}" data-testid="occurrence-{{ $occurrence->id }}">
                                                <span class="w-28 tabular-nums">{{ $occurrence->effectiveDueDate()->format('Y-m-d') }}</span>
                                                <flux:badge size="sm" :color="match($state->value) { 'overdue' => 'red', 'due' => 'amber', 'skipped', 'missed' => 'zinc', 'dormant' => 'yellow', default => 'green' }" data-testid="occurrence-state">{{ $state->label() }}</flux:badge>
                                                <span class="w-24 tabular-nums">{{ MoneyCast::format(abs($occurrence->effectiveExpectedAmount())) }}</span>
                                                <flux:button size="sm" variant="ghost" wire:click="$dispatch('open-occurrence-payment-panel', { occurrenceId: {{ $occurrence->id }} })" data-testid="occurrence-payments">{{ __('Payments') }}</flux:button>
                                                @if($occurrence->status === \App\Enums\OccurrenceStatus::Scheduled)
                                                    <flux:button size="sm" variant="ghost" wire:click="skipOccurrence({{ $occurrence->id }})" data-testid="occurrence-skip">{{ __('Skip') }}</flux:button>
                                                    <flux:button size="sm" variant="ghost" wire:click="markOccurrenceMissed({{ $occurrence->id }})" data-testid="occurrence-missed">{{ __('Mark missed') }}</flux:button>
                                                    <input type="date" class="rounded border border-neutral-300 px-1 py-0.5" wire:model="snoozeDates.{{ $occurrence->id }}" aria-label="{{ __('Snooze until') }}" data-testid="occurrence-snooze-date">
                                                    <flux:button size="sm" variant="ghost" wire:click="snoozeOccurrence({{ $occurrence->id }})" data-testid="occurrence-snooze">{{ __('Snooze') }}</flux:button>
                                                    <input type="text" inputmode="decimal" class="w-24 rounded border border-neutral-300 px-1 py-0.5" wire:model="occurrenceAmounts.{{ $occurrence->id }}" placeholder="{{ __('Amount') }}" aria-label="{{ __('Expected amount') }}" data-testid="occurrence-amount">
                                                    <flux:button size="sm" variant="ghost" wire:click="setOccurrenceAmount({{ $occurrence->id }})" data-testid="occurrence-amount-set">{{ __('Set amount') }}</flux:button>
                                                @else
                                                    <flux:button size="sm" variant="ghost" wire:click="reopenOccurrence({{ $occurrence->id }})" data-testid="occurrence-reopen">{{ __('Reopen') }}</flux:button>
                                                @endif
                                            </div>
                                        @empty
                                            <flux:text size="sm" class="text-zinc-500">{{ __('No stored occurrences from the current cycle on.') }}</flux:text>
                                        @endforelse
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="flex flex-col items-center justify-center rounded-lg border border-dashed border-neutral-200 py-10 text-center">
                <flux:icon.calendar-days class="mb-3 size-10 text-zinc-400" />
                <flux:heading size="sm">No planned transactions for this account yet.</flux:heading>
            </div>
        @endif
    </div>

    <livewire:plan-declaration-form />

    <flux:modal wire:model="showEditModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Edit planned transaction</flux:heading>

            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-1">

                <flux:field>
                    <flux:label>Amount</flux:label>
                    <flux:input wire:model="amount" type="number" step="0.01" min="0.01" placeholder="0.00" />
                    <flux:error name="amount" />
                </flux:field>

                <flux:field>
                    <flux:label>Frequency</flux:label>
                    <flux:select wire:model.live="frequency" data-testid="plan-frequency">
                        <flux:select.option value="">Select frequency</flux:select.option>
                        @foreach($frequencies as $recurrenceFrequency)
                            <flux:select.option value="{{ $recurrenceFrequency->value }}">{{ $recurrenceFrequency->isPickable() ? $recurrenceFrequency->pickerLabel() : 'Custom' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="frequency" />
                </flux:field>

                <x-cadence-fields :frequency="$frequency" scope="plan" />

                <flux:field>
                    <flux:label>Direction</flux:label>
                    <flux:select wire:model.live="direction">
                        <flux:select.option value="">Select direction</flux:select.option>
                        @foreach($directions as $transactionDirection)
                            <flux:select.option value="{{ $transactionDirection->value }}">{{ Str::headline($transactionDirection->value) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="direction" />
                </flux:field>

                <x-category-combobox
                    wire:model="categoryId"
                    :categories="$categories"
                    label="Category"
                    placeholder="No category"
                />

                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>Until date</flux:label>
                        <flux:input wire:model="untilDate" type="date" />
                        <flux:error name="untilDate" />
                    </flux:field>

                    <flux:field>
                        <flux:label>End after (payments)</flux:label>
                        <flux:input wire:model="endAfterCount" type="number" min="1" max="600" step="1" placeholder="Never" data-testid="plan-end-after-count" />
                        <flux:error name="endAfterCount" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Merchant</flux:label>
                    <flux:select wire:model="merchantId" data-testid="plan-merchant-select">
                        <flux:select.option value="">No merchant</flux:select.option>
                        @foreach($merchants as $merchant)
                            <flux:select.option value="{{ $merchant->id }}">{{ $merchant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="merchantId" />
                </flux:field>

                @if($priceChanges->isNotEmpty())
                    <div data-testid="plan-price-changes">
                        <flux:heading size="sm">{{ __('Price changes') }}</flux:heading>
                        @foreach($priceChanges as $change)
                            <flux:text size="sm" class="text-zinc-600" wire:key="price-change-{{ $change->id }}">
                                {{ $change->effective_on->format('Y-m-d') }}: {{ MoneyCast::format(abs($change->previous_amount)) }} → {{ MoneyCast::format(abs($change->new_amount)) }}
                            </flux:text>
                        @endforeach
                    </div>
                @endif

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="notes" rows="2" maxlength="1000" />
                    <flux:error name="notes" />
                </flux:field>

                <x-plan-series-fields :type-choices="$typeChoices" :amount-strategies="$amountStrategies" :has-link="filled($paymentUrl)" />

                <flux:field>
                    <flux:checkbox wire:model="isActive" label="Active" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showEditModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="save">Update</flux:button>
            </div>
        </div>
    </flux:modal>

    <livewire:occurrence-payment-panel />

    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Delete planned transaction</flux:heading>
            <flux:text>Are you sure you want to delete <strong>{{ $deletingDescription }}</strong>?</flux:text>
            <flux:text size="sm" class="text-zinc-500">{{ __('A plan you declared is removed with its occurrences and payments. A plan found by detection is kept out of sight so it is not suggested again.') }}</flux:text>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete" data-testid="plan-delete-confirm">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:card>
