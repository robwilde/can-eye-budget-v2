@use('App\Casts\MoneyCast')

<section class="mf space-y-3" data-test="cash-flow-widget">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-cib.sec-head :title="__('Cash flow')"/>
        <x-cib.grouping-toggle :grouping="$this->grouping"/>
    </div>

    <p class="mf-sub" data-test="cash-flow-widget-summary">{{ __('Where :income came in and :spending went out over :period', ['income' => MoneyCast::format($this->cashFlow->income), 'spending' => MoneyCast::format($this->cashFlow->spending), 'period' => $this->period->label()]) }}</p>

    @if ($this->cashFlow->isEmpty())
        <p class="mf-empty" data-test="cash-flow-widget-empty">{{ __('No income or spending recorded for :period yet.', ['period' => $this->period->label()]) }}</p>
    @else
        <x-cib.cash-flow-sankey :cash-flow="$this->cashFlow" :scope="$this->period->stamp().'-'.$this->grouping->value" :root-title="$this->grouping === \App\Enums\CashFlowGrouping::Account ? __('All accounts') : __('All categories')"/>
    @endif
</section>
