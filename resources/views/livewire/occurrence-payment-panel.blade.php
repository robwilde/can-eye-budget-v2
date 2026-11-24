@php
    use App\Casts\MoneyCast;
    use App\Models\PlannedOccurrence;
@endphp
<div>
    <flux:modal wire:model.self="showPanel" class="w-full max-w-2xl" data-testid="occurrence-payment-panel">
        @if($occurrence instanceof PlannedOccurrence)
            @php
                $plan = $occurrence->plannedTransaction;
                $expected = abs($occurrence->effectiveExpectedAmount());
                $paid = (int) $payments->sum('amount');
                $open = $occurrence->status === \App\Enums\OccurrenceStatus::Scheduled;
                $aliases = array_values(array_filter([...($plan->learned_aliases['descriptions'] ?? []), ...($plan->learned_aliases['merchant_keys'] ?? [])]));
            @endphp
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $plan->description ?? __('Planned payment') }}</flux:heading>
                    <flux:text class="mt-1" data-testid="panel-summary">
                        {{ __('Due :date', ['date' => $occurrence->effectiveDueDate()->format('Y-m-d')]) }}
                        · {{ __('expected :amount', ['amount' => MoneyCast::format($expected)]) }}
                        · {{ __('paid :amount', ['amount' => MoneyCast::format($paid)]) }}
                        · {{ (new \App\Services\Recurring\OccurrenceStateResolver)->resolve($occurrence, auth()->user()->today())->label() }}
                    </flux:text>
                    <flux:text size="sm" class="mt-1 text-zinc-500" data-testid="panel-matching">
                        {{ __('Matches within :percent% of the amount', ['percent' => rtrim(rtrim(number_format($plan->amount_tolerance_bps / 100, 2), '0'), '.')]) }}
                        @if($aliases !== [])
                            · {{ __('also known as: :aliases', ['aliases' => implode(', ', $aliases)]) }}
                        @endif
                    </flux:text>
                </div>

                @error('payment')
                    <p class="text-sm text-red-700" data-testid="payment-error" role="alert">{{ $message }}</p>
                @enderror

                <div data-testid="panel-payments">
                    <flux:heading size="sm">{{ __('Payments') }}</flux:heading>
                    @forelse($payments as $payment)
                        <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 py-2 text-sm" wire:key="payment-{{ $payment->id }}" data-testid="payment-{{ $payment->id }}">
                            <span class="w-24 tabular-nums">{{ $payment->paid_on->format('Y-m-d') }}</span>
                            <span class="w-24 tabular-nums">{{ MoneyCast::format($payment->amount) }}</span>
                            <span class="flex-1 text-zinc-600" data-testid="payment-source">
                                @if($payment->transaction === null)
                                    {{ __('Recorded by hand') }}
                                @elseif($payment->transaction->trashed())
                                    {{ __('transaction removed') }}
                                @else
                                    {{ $payment->transaction->description }}
                                @endif
                            </span>
                            <flux:button size="sm" variant="ghost" wire:click="removePayment({{ $payment->id }})" wire:confirm="{{ __('Remove this payment?') }}" data-testid="payment-remove">{{ __('Remove') }}</flux:button>
                        </div>
                    @empty
                        <flux:text size="sm" class="text-zinc-500">{{ __('Nothing paid yet.') }}</flux:text>
                    @endforelse
                </div>

                @if($open)
                    <div class="flex flex-wrap items-end gap-2" data-testid="panel-record">
                        <flux:input wire:model="recordAmount" inputmode="decimal" :label="__('Record a payment without a transaction')" placeholder="0.00" class="w-40" data-testid="record-amount" />
                        <flux:input wire:model="recordDate" type="date" :label="__('Paid on')" class="w-44" data-testid="record-date" />
                        <flux:button size="sm" wire:click="recordPayment" data-testid="record-submit">{{ __('Record') }}</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="markPaid" wire:confirm="{{ __('Mark this as paid in full?') }}" data-testid="mark-paid">{{ __('Mark paid') }}</flux:button>
                    </div>

                    <div data-testid="panel-candidates">
                        <flux:input wire:model.live.debounce.300ms="search" :label="__('Find a transaction')" placeholder="{{ __('Search descriptions, any date') }}" data-testid="candidate-search" />

                        <flux:heading size="sm" class="mt-4">{{ __('Best matches') }}</flux:heading>
                        @forelse($candidates['ranked'] as $candidate)
                            @include('livewire.partials.payment-candidate-row', ['candidate' => $candidate, 'section' => 'ranked'])
                        @empty
                            <flux:text size="sm" class="text-zinc-500" data-testid="no-ranked">{{ __('No transaction matches this bill closely.') }}</flux:text>
                        @endforelse

                        @if($candidates['fallback']->isNotEmpty())
                            <flux:heading size="sm" class="mt-4">{{ __('Other transactions') }}</flux:heading>
                            @foreach($candidates['fallback'] as $candidate)
                                @include('livewire.partials.payment-candidate-row', ['candidate' => $candidate, 'section' => 'fallback'])
                            @endforeach
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
