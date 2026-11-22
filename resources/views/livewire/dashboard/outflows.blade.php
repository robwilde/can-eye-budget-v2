@use('App\Casts\MoneyCast')

<section class="mf space-y-3" data-test="outflows-widget">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-cib.sec-head :title="__('Outflows')"/>
        <x-cib.grouping-toggle :grouping="$this->grouping"/>
    </div>

    <p class="mf-sub" data-test="outflows-widget-total">{{ __('Outflows totalling :spending over :period', ['spending' => MoneyCast::format($this->cashFlow->spending), 'period' => $this->period->label()]) }}</p>

    @if ($this->cashFlow->outflows === [])
        <p class="mf-empty" data-test="outflows-widget-empty">{{ __('No spending recorded for :period yet.', ['period' => $this->period->label()]) }}</p>
    @else
        <x-cib.outflows :cash-flow="$this->cashFlow" :scope="$this->period->stamp().'-'.$this->grouping->value" :from="$this->period->start->toDateString()" :to="$this->period->end->toDateString()" :label="$this->grouping === \App\Enums\CashFlowGrouping::Account ? __('Accounts') : __('Categories')"/>
    @endif
</section>
