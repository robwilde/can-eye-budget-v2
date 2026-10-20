@php
    $sankeyPayload = ['overview' => $cashFlow->overview, 'drilldowns' => $cashFlow->drilldowns];
    $sankeyKey = $this->currentMonth.'-'.md5((string) json_encode($sankeyPayload));
@endphp
<header class="mf-head">
    <h2 class="mf-verdict" data-testid="cash-flow-heading">Where {{ $formatMoney($cashFlow->income) }} came in and {{ $formatMoney($cashFlow->spending) }} went out</h2>
    <p class="mf-sub">Every figure is net by category: a refund inside a category reduces that category rather than counting as income. Actuals only; planned-but-unposted flows are not drawn. Transfers between a tracked and an untracked account are counted, under Savings &amp; Investments / Transfer.</p>
</header>

@if ($cashFlow->isEmpty())
    <p class="mf-empty" data-testid="cash-flow-empty">No income or spending recorded for {{ $this->headerLabel['label'] }} yet.</p>
@else
    <div wire:key="sankey-{{ $sankeyKey }}" x-data="moneySankey(@js($sankeyPayload))" data-testid="cash-flow">
        <div class="mf-chart-head">
            <button type="button" class="mf-back" data-testid="cash-flow-back" x-ref="back" x-show="drilled" x-cloak @click="back()">
                <flux:icon.chevron-left variant="micro"/> Back
            </button>
            <span class="mf-chart-title" x-show="drilled" x-cloak x-text="title"></span>
        </div>

        <div class="mf-canvas" x-ref="canvas" wire:ignore></div>

        <details class="mf-data" data-testid="cash-flow-data">
            <summary>View data</summary>
            <table>
                <thead><tr><th scope="col">From</th><th scope="col">To</th><th scope="col" class="num">Amount</th></tr></thead>
                <tbody>
                    <template x-for="row in rows" :key="row.key">
                        <tr>
                            <td x-text="row.from"></td>
                            <td x-text="row.to"></td>
                            <td class="num" x-text="row.amount"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </details>
    </div>
@endif
