@use('App\Casts\MoneyCast')
<div>
    <flux:modal wire:model.self="showDrawer" variant="flyout" position="right" class="w-full max-w-xl" data-testid="bill-drawer">
        @if($detail instanceof \App\DTOs\BillDetail)
            @php($plan = $detail->plan)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $plan->description }}</flux:heading>
                    <flux:text class="mt-1" data-testid="drawer-cadence">{{ $detail->cadence }} · {{ $plan->type->label() }} · {{ $plan->status->label() }}</flux:text>
                </div>

                @if($detail->installmentTotal !== null)
                    <div data-testid="drawer-installment">
                        <flux:heading size="sm">{{ __(':paid paid of :total', ['paid' => $detail->installmentPaid, 'total' => $detail->installmentTotal]) }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-600">{{ collect($detail->installmentDates)->map(fn ($date) => $date->format('Y-m-d'))->implode(', ') }}</flux:text>
                    </div>
                @endif

                @if($detail->current !== null)
                    <div data-testid="drawer-current">
                        <flux:heading size="sm">{{ __('Current cycle') }}</flux:heading>
                        <flux:text size="sm">{{ $detail->current->dueOn->format('Y-m-d') }} · {{ $detail->current->state->label() }} · {{ MoneyCast::format($detail->current->paid) }} / {{ MoneyCast::format($detail->current->expected) }}</flux:text>
                        @if($detail->current->occurrenceId !== null)
                            <flux:button size="sm" variant="ghost" wire:click="$dispatch('open-occurrence-payment-panel', { occurrenceId: {{ $detail->current->occurrenceId }} })" data-testid="drawer-payments">{{ __('Payments') }}</flux:button>
                        @endif
                    </div>
                @endif

                @if($detail->suggestion !== null)
                    <flux:callout icon="sparkles" data-testid="drawer-suggestion">
                        <flux:callout.text>{{ __('A payment may match this bill: :description on :date for :amount.', ['description' => $detail->suggestion['transaction']->description, 'date' => $detail->suggestion['transaction']->post_date->format('Y-m-d'), 'amount' => MoneyCast::format(abs($detail->suggestion['transaction']->amount))]) }}</flux:callout.text>
                        <x-slot name="actions"><flux:button size="sm" href="{{ route('rules', ['account' => $detail->suggestion['suggestion']->payload['account_id'] ?? null]) }}" wire:navigate>{{ __('Review') }}</flux:button></x-slot>
                    </flux:callout>
                @endif

                <div data-testid="drawer-stats">
                    <flux:heading size="sm">{{ __('Last :count settled cycles', ['count' => count($detail->cycles)]) }}</flux:heading>
                    <flux:text size="sm" data-testid="drawer-summary">{{ __('Average :average · low :low · high :high', ['average' => MoneyCast::format($detail->average), 'low' => MoneyCast::format($detail->low), 'high' => MoneyCast::format($detail->high)]) }}</flux:text>
                    <flux:text size="sm" class="text-zinc-600">{{ __('Annualised :annual · paid this year :ytd', ['annual' => MoneyCast::format($detail->annualised), 'ytd' => MoneyCast::format($detail->yearToDate)]) }}</flux:text>
                    @foreach($detail->cycles as $cycle)
                        <flux:text size="sm" class="text-zinc-600" wire:key="cycle-{{ $loop->index }}">{{ $cycle['date']->format('Y-m-d') }} · {{ MoneyCast::format($cycle['paid']) }}</flux:text>
                    @endforeach
                </div>

                <div data-testid="drawer-next-dates">
                    <flux:heading size="sm">{{ __('Next dates') }}</flux:heading>
                    <flux:text size="sm">{{ collect($detail->nextDates)->map(fn ($date) => $date->format('Y-m-d'))->implode(', ') ?: __('None') }}</flux:text>
                </div>

                <div data-testid="drawer-chart">
                    <flux:heading size="sm">{{ __('Last 12 months') }}</flux:heading>
                    @php($peak = max(1, ...array_column($detail->monthly, 'paid')))
                    <div class="flex items-end gap-1" style="height: 5rem">
                        @foreach($detail->monthly as $month)
                            <div class="flex-1" style="background: #0d9488; height: {{ max(2, (int) round($month['paid'] / $peak * 100)) }}%" title="{{ $month['month'] }}: {{ MoneyCast::format($month['paid']) }}" wire:key="m-{{ $month['month'] }}"></div>
                        @endforeach
                    </div>
                </div>

                <div data-testid="drawer-yearly">
                    <flux:heading size="sm">{{ __('Totals per year') }}</flux:heading>
                    @foreach($detail->yearly as $year => $total)
                        <flux:text size="sm" wire:key="y-{{ $year }}">{{ $year }}: {{ MoneyCast::format($total) }}</flux:text>
                    @endforeach
                </div>

                <flux:text size="sm" class="text-zinc-600" data-testid="drawer-matching">
                    {{ $detail->lastPaidFrom !== null ? __('Last paid from :account.', ['account' => $detail->lastPaidFrom]).' ' : '' }}{{ __('Matches within :percent% of the amount', ['percent' => rtrim(rtrim(number_format($plan->amount_tolerance_bps / 100, 2), '0'), '.')]) }}@if(array_values(array_filter([...($plan->learned_aliases['descriptions'] ?? []), ...($plan->learned_aliases['merchant_keys'] ?? [])])) !== []) · {{ __('also known as: :aliases', ['aliases' => implode(', ', array_values(array_filter([...($plan->learned_aliases['descriptions'] ?? []), ...($plan->learned_aliases['merchant_keys'] ?? [])])))]) }}@endif
                </flux:text>
            </div>
        @endif
    </flux:modal>
</div>
