@php
    $monthFrom = $this->currentMonth.'-01';
    $monthTo = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $monthFrom)->endOfMonth()->toDateString();
@endphp
<header class="mf-head">
    <h2 class="mf-verdict">
        Outflows totalling
        <span data-testid="money-flow-outflows-total">{{ $formatMoney($cashFlow->spending) }}</span>
    </h2>
    <p class="mf-sub">This total equals the Cash flow expense total. Every figure is net by category: a refund inside a category reduces that category. Transfers between a tracked and an untracked account are counted, under Savings &amp; Investments / Transfer.</p>
</header>

@if ($cashFlow->outflows === [])
    <p class="mf-empty" data-testid="outflows-empty">No spending recorded for {{ $this->headerLabel['label'] }} yet.</p>
@else
    <x-cib.outflows :cash-flow="$cashFlow" :scope="$this->currentMonth" :from="$monthFrom" :to="$monthTo"/>
@endif
