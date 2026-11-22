@use('App\Casts\MoneyCast')

@php
    $overview = $this->overview;
    $pct = static fn (?int $bp): string => $bp === null ? '--' : number_format($bp / 100, 2).'%';
@endphp
<section class="investment-summary space-y-4" data-test="investment-summary">
    <x-cib.sec-head :title="__('Investments')"/>

    <div class="grid gap-3 sm:grid-cols-3">
        <div>
            <div class="cib-label">{{ __('Portfolio value') }}</div>
            <div class="text-2xl font-black" data-test="investment-portfolio-value">{{ MoneyCast::format($overview['portfolioValue']) }}</div>
        </div>
        <div>
            <div class="cib-label">{{ $this->period->label() }}</div>
            <div class="text-2xl font-black" data-test="investment-period-return">{{ $pct($overview['periodReturnBp']) }}</div>
        </div>
        <div>
            <div class="cib-label">{{ __('Day change') }}</div>
            <div class="text-2xl font-black" data-test="investment-day-change">{{ $overview['dayChange'] === null ? '--' : MoneyCast::format($overview['dayChange']) }}</div>
        </div>
    </div>

    <ul class="space-y-1" data-test="investment-top-holdings">
        @foreach ($overview['top'] as $row)
            <li class="flex justify-between text-sm" wire:key="investment-top-{{ $row['holding']->id }}">
                <span>{{ $row['security']->ticker }}</span>
                <span>{{ MoneyCast::format($row['valueBase']) }} · {{ $pct($row['weightBp']) }}</span>
            </li>
        @endforeach
    </ul>
</section>
