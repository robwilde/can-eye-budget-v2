@use('App\Casts\MoneyCast')
<section class="paycheck-plan space-y-3" data-testid="paycheck-plan">
    <x-cib.sec-head title="Paycheck plan"/>

    @php($plan = $this->plan)

    @if($plan->needsIncome)
        <div class="space-y-2" data-testid="paycheck-prompt">
            <flux:text>{{ __('Add your regular income to see which paycheck covers each bill and what is safe to spend.') }}</flux:text>
            @foreach($plan->ignoredIncome as $ignored)
                <flux:text size="sm" class="text-zinc-500" data-testid="paycheck-ignored">{{ $ignored['name'] }} · {{ $ignored['reason'] }}</flux:text>
            @endforeach
            <flux:button size="sm" variant="primary" wire:click="$dispatch('declare-plan', { kind: 'income' })" data-testid="paycheck-add-income">{{ __('Add income') }}</flux:button>
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($plan->periods as $period)
                <flux:card class="space-y-1 text-sm" wire:key="period-{{ $period->index }}" data-testid="{{ $period->index === 0 ? 'pay-bridge' : 'pay-period' }}">
                    <div class="font-medium">
                        @if($period->index === 0)
                            {{ __('Until your next payday') }}
                        @else
                            {{ __('Payday :date', ['date' => $period->startsOn->format('D j M')]) }}
                        @endif
                    </div>
                    <flux:text size="sm" class="text-zinc-500">{{ $period->startsOn->format('j M') }} – {{ $period->endsOn->format('j M') }}@if($period->sources !== []) · {{ implode(', ', $period->sources) }}@endif</flux:text>
                    @if($period->index > 0)
                        <div class="flex justify-between"><span>{{ __('Income') }}</span><span class="tabular-nums" data-testid="period-income">{{ MoneyCast::format($period->income) }}</span></div>
                    @endif
                    <div class="flex justify-between"><span>{{ __('Bills due') }}</span><span class="tabular-nums" data-testid="period-bills">{{ MoneyCast::format($period->billsDue) }}</span></div>
                    @if($period->fundedFromEarlier > 0)
                        <div class="flex justify-between"><span>{{ __('Covered by earlier paychecks') }}</span><span class="tabular-nums">{{ MoneyCast::format($period->fundedFromEarlier) }}</span></div>
                    @endif
                    @if($period->reserved > 0)
                        <div class="flex justify-between"><span>{{ __('Reserved for later bills') }}</span><span class="tabular-nums">{{ MoneyCast::format($period->reserved) }}</span></div>
                    @endif
                    @if($period->largestName !== null)
                        <flux:text size="sm" class="text-zinc-500">{{ __('Largest: :name :amount', ['name' => $period->largestName, 'amount' => MoneyCast::format($period->largestAmount)]) }}</flux:text>
                    @endif
                    @if($period->shortfall > 0)
                        <div class="flex justify-between font-medium text-red-700" data-testid="period-shortfall"><span>{{ __('Short by') }}</span><span class="tabular-nums">{{ MoneyCast::format($period->shortfall) }}</span></div>
                    @elseif($period->safeToSpend !== null)
                        <div class="flex justify-between font-medium" data-testid="period-safe"><span>{{ __('Safe to spend') }}</span><span class="tabular-nums">{{ MoneyCast::format($period->safeToSpend) }}</span></div>
                    @endif
                </flux:card>
            @endforeach
        </div>

        @if($periods < 6)
            <flux:button size="sm" variant="ghost" wire:click="showPeriods({{ $periods + 3 }})" data-testid="paycheck-show-more">{{ __('Show more periods') }}</flux:button>
        @endif
    @endif

    <livewire:plan-declaration-form />
</section>
