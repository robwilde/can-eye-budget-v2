@use('App\Casts\MoneyCast')

@php
    $performance = $this->report;
    $pct = static fn (?int $bp): string => $bp === null ? '--' : number_format($bp / 100, 2).'%';
    $money = static fn (?int $cents): string => $cents === null ? '--' : MoneyCast::format($cents);
@endphp

<div class="space-y-4" data-testid="report-investment-performance">
    <div class="grid gap-3 sm:grid-cols-3">
        <x-cib.money-card label="Portfolio value" :amount="$performance['portfolioValue']" hint="Holdings {{ MoneyCast::format($performance['holdingsValue']) }}"/>
        <div>
            <div class="cib-label">Total return</div>
            <div data-testid="investment-total-return">{{ $pct($performance['totalReturnBp']) }}</div>
        </div>
        <div>
            <div class="cib-label">Return over the period</div>
            <div data-testid="investment-period-return">{{ $pct($performance['periodReturnBp']) }}</div>
        </div>
        <div>
            <div class="cib-label">Contributions</div>
            <div data-testid="investment-contributions">{{ MoneyCast::format($performance['contributions']) }}</div>
        </div>
        <div>
            <div class="cib-label">Withdrawals</div>
            <div data-testid="investment-withdrawals">{{ MoneyCast::format($performance['withdrawals']) }}</div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div data-testid="investment-top-holdings">
            <div class="font-bold">Top holdings</div>
            @forelse ($performance['top'] as $row)
                <div class="flex items-center justify-between gap-3 py-1 text-sm" wire:key="top-{{ $row['holding']->id }}">
                    <span class="flex-1">{{ $row['security']->ticker }}</span>
                    <span>{{ MoneyCast::format($row['valueBase']) }}</span>
                    <span class="w-16 text-right">{{ $pct($row['weightBp']) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">No holdings yet.</p>
            @endforelse
        </div>

        <div data-testid="investment-gains">
            <div class="font-bold">Gains</div>
            <div class="flex items-center justify-between py-1 text-sm"><span>Unrealised</span><span data-testid="investment-unrealised">{{ $money($performance['unrealised']) }}</span></div>
            <div class="flex items-center justify-between py-1 text-sm"><span>Realised in the period</span><span data-testid="investment-realised">{{ MoneyCast::format($performance['realised']) }}</span></div>
            @if ($performance['withheld'] > 0)
                <p class="text-sm text-gray-500" data-testid="investment-realised-withheld">{{ trans_choice(':count sale has no realised gain: its cost or exchange rate is unknown.|:count sales have no realised gain: their cost or exchange rate is unknown.', $performance['withheld']) }}</p>
            @endif
        </div>
    </div>

    @if ($performance['sales'] !== [])
        <div data-testid="investment-sales">
            <div class="font-bold">Sales in the period</div>
            @foreach ($performance['sales'] as $sale)
                <div class="flex items-center justify-between gap-3 py-1 text-sm" wire:key="sale-{{ $sale['trade']->id }}">
                    <span class="w-24">{{ $sale['trade']->date->format('j M Y') }}</span>
                    <span class="flex-1">{{ $sale['trade']->name }}</span>
                    <span>{{ $money($sale['gain']) }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
