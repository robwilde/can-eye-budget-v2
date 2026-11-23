@php
    use App\Casts\MoneyCast;
@endphp

<div>
    <flux:modal wire:model="showModal" class="max-w-lg" data-testid="plan-declaration-modal">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $kind === 'income' ? __('Add income') : __('Add bill') }}</flux:heading>

            <div class="max-h-[60vh] space-y-4 overflow-y-auto px-1">
                @if($patterns->isNotEmpty())
                    <div class="space-y-2" data-testid="declare-patterns">
                        <flux:text size="sm" class="font-medium">{{ $kind === 'income' ? __('Looks like regular income') : __('Looks like regular bills') }}</flux:text>
                        <ul class="space-y-1">
                            @foreach($patterns as $pattern)
                                <li wire:key="pattern-{{ $pattern->key }}">
                                    <button type="button" wire:click="usePattern('{{ $pattern->key }}')" class="flex w-full items-center justify-between gap-3 rounded-lg border border-neutral-200 px-3 py-2 text-left text-sm hover:bg-neutral-50" data-testid="declare-pattern">
                                        <span class="font-medium">{{ $pattern->candidate->cleanDescription }}</span>
                                        <span class="text-zinc-500">{{ MoneyCast::format(abs($pattern->candidate->amount)) }} · {{ $pattern->candidate->frequency->label() }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <flux:error name="pattern" />
                    </div>
                @endif

                <details wire:ignore.self class="rounded-lg border border-neutral-200 px-3 py-2" data-testid="declare-search-panel" @if($searchTerm !== '') open @endif>
                    <summary class="cursor-pointer text-sm font-medium">{{ __("Not seeing what you're looking for?") }}</summary>

                    <div class="mt-3 space-y-2">
                        <flux:input wire:model.live.debounce.400ms="searchTerm" type="search" maxlength="100" :placeholder="__('Search your transactions')" :aria-label="__('Search your transactions')" data-testid="declare-search" />

                        @if($results !== null)
                            @forelse($results as $transaction)
                                <button type="button" wire:key="found-{{ $transaction->id }}" wire:click="startFromTransaction({{ $transaction->id }})" class="flex w-full items-center justify-between gap-3 rounded-lg border border-neutral-200 px-3 py-2 text-left text-sm hover:bg-neutral-50" data-testid="declare-found">
                                    <span>
                                        <span class="font-medium">{{ $transaction->merchant_name ?: $transaction->description }}</span>
                                        <span class="block text-xs text-zinc-500">{{ $transaction->post_date->format('j M Y') }}</span>
                                        @if($transaction->plannedTransaction !== null)
                                            <span class="block text-xs text-zinc-600" data-testid="declare-found-plan">{{ __('Part of :plan', ['plan' => $transaction->plannedTransaction->description]) }}</span>
                                        @endif
                                    </span>
                                    <span class="text-zinc-500">{{ MoneyCast::format(abs($transaction->amount)) }}</span>
                                </button>
                            @empty
                                <flux:text size="sm" class="text-zinc-500" data-testid="declare-found-none">{{ __('No matching transactions.') }}</flux:text>
                            @endforelse

                            @if($results->hasPages())
                                <div class="flex items-center justify-between text-sm">
                                    <flux:button size="xs" variant="ghost" wire:click="previousPage('searchPage')" :disabled="$results->onFirstPage()">{{ __('Newer') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="nextPage('searchPage')" :disabled="! $results->hasMorePages()" data-testid="declare-found-next">{{ __('Older') }}</flux:button>
                                </div>
                            @endif
                        @endif
                    </div>
                </details>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:input wire:model="description" type="text" maxlength="255" placeholder="{{ $kind === 'income' ? __('Salary') : __('Rent') }}" data-testid="declare-description" />
                    <flux:error name="description" />
                </flux:field>

                @if($smartFillAvailable)
                    <div class="space-y-1" data-testid="declare-smart-fill">
                        <flux:button size="sm" wire:click="smartFill" wire:loading.attr="disabled" wire:target="smartFill" data-testid="declare-smart-fill-button">
                            <span wire:loading.remove wire:target="smartFill">{{ __('Smart fill from my past charges') }}</span>
                            <span wire:loading wire:target="smartFill">{{ __('Looking at your charges...') }}</span>
                        </flux:button>
                        <div role="status" aria-live="polite">
                            @if($smartFillNotice !== '')
                                <flux:text size="sm" class="text-zinc-600" data-testid="declare-smart-fill-notice">{{ $smartFillNotice }}</flux:text>
                            @endif
                            <flux:error name="smartFill" />
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>{{ __('Amount') }}</flux:label>
                        <flux:input wire:model="amount" type="number" step="0.01" min="0.01" placeholder="0.00" data-testid="declare-amount" />
                        <flux:error name="amount" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ $kind === 'income' ? __('First pay date') : __('First due date') }}</flux:label>
                        <flux:input wire:model="startDate" type="date" data-testid="declare-start-date" />
                        <flux:error name="startDate" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Repeats') }}</flux:label>
                    <flux:select wire:model="frequency" data-testid="declare-frequency">
                        @foreach($frequencies as $recurrenceFrequency)
                            <flux:select.option value="{{ $recurrenceFrequency->value }}">{{ $recurrenceFrequency->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="frequency" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Type') }}</flux:label>
                    <flux:select wire:model="planType" data-testid="declare-type">
                        @foreach($typeOptions as $typeOption)
                            <flux:select.option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="planType" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ $kind === 'income' ? __('Paid into') : __('Paid from') }}</flux:label>
                    <flux:select wire:model="accountId" data-testid="declare-account">
                        <flux:select.option value="">{{ __('Choose an account') }}</flux:select.option>
                        @foreach($accounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="accountId" />
                </flux:field>

                <x-category-combobox
                    wire:model="categoryId"
                    :categories="$categories"
                    :label="__('Category')"
                    :placeholder="__('No category')"
                />

                <flux:field>
                    <flux:label>{{ __('Merchant') }}</flux:label>
                    <flux:select wire:model="merchantId" data-testid="declare-merchant">
                        <flux:select.option value="">{{ __('No merchant') }}</flux:select.option>
                        @foreach($merchants as $merchant)
                            <flux:select.option value="{{ $merchant->id }}">{{ $merchant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="merchantId" />
                </flux:field>

                @if($kind !== 'income')
                    <flux:field>
                        <flux:label>{{ __('Payment link') }}</flux:label>
                        <flux:input wire:model.live.blur="paymentUrl" type="text" inputmode="url" maxlength="2048" autocomplete="off" placeholder="example.com/pay" data-testid="declare-payment-url" />
                        <flux:error name="paymentUrl" />
                    </flux:field>

                    <flux:checkbox wire:model="autopay" :label="__('Autopay')" :description="__('The biller takes this payment automatically.')" data-testid="declare-autopay" />
                @endif

                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="2" maxlength="1000" />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="save" data-testid="declare-save">{{ $kind === 'income' ? __('Add income') : __('Add bill') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
