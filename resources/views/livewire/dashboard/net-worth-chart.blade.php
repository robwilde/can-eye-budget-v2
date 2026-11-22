@use('App\Casts\MoneyCast')

<section class="net-worth space-y-4" data-test="net-worth">
    <x-cib.sec-head :title="__('Net worth')"/>

    <div>
        <div @class([
            'text-4xl font-black',
            'text-cib-green-600' => $this->favourable,
            'text-red-600' => ! $this->favourable,
        ]) data-test="net-worth-figure">{{ MoneyCast::format($this->current) }}</div>
        <p class="text-sm text-gray-500">{{ __('Assets minus debts') }}@if (config('budget.multi_currency')) <span data-test="net-worth-base-currency">{{ __('All amounts converted to :currency', ['currency' => auth()->user()->base_currency]) }}</span>@endif</p>
        @if ($this->velocity !== null)
            <p class="text-sm" data-test="net-worth-velocity">
                {{ ($this->velocity >= 0 ? '+' : '-').MoneyCast::format(abs($this->velocity)) }} {{ __('over :period', ['period' => $this->period->label()]) }}
            </p>
        @else
            <p class="text-sm text-gray-500" data-test="net-worth-velocity-withheld">{{ __('Change is not shown: balance history does not cover :period.', ['period' => $this->period->label()]) }}</p>
        @endif
    </div>

    <x-cib.balance-chart :points="$this->series" :favourable="$this->favourable" :label="__('Net worth')" data-test="net-worth-chart"/>
</section>
