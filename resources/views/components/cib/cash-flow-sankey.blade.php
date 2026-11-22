@props(['cashFlow', 'scope', 'rootTitle' => 'All categories'])

@php
    $sankeyPayload = ['overview' => $cashFlow->overview, 'drilldowns' => $cashFlow->drilldowns, 'rootTitle' => $rootTitle];
    $sankeyKey = $scope.'-'.md5((string) json_encode($sankeyPayload));
@endphp
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
