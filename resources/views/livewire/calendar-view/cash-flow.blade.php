<header class="mf-head">
    <h2 class="mf-verdict" data-testid="cash-flow-heading">Where {{ $formatMoney($cashFlow->income) }} came in and {{ $formatMoney($cashFlow->spending) }} went out</h2>
    <p class="mf-sub">Every figure is net by category: a refund inside a category reduces that category rather than counting as income. Actuals only; planned-but-unposted flows are not drawn. Transfers between a tracked and an untracked account are counted, under Savings &amp; Investments / Transfer.</p>
</header>

@if ($cashFlow->isEmpty())
    <p class="mf-empty" data-testid="cash-flow-empty">No income or spending recorded for {{ $this->headerLabel['label'] }} yet.</p>
@else
    <x-cib.cash-flow-sankey :cash-flow="$cashFlow" :scope="$this->currentMonth"/>
@endif
