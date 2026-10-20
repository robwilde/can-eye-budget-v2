@php
    $monthFrom = $this->currentMonth.'-01';
    $monthTo = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $monthFrom)->endOfMonth()->toDateString();
    $outflowsKey = $this->currentMonth.'-'.md5((string) json_encode($cashFlow->outflows));
    $outflowLink = static fn (array $row): ?string => $row['groupId'] === null && ! $row['uncategorised']
        ? null
        : route('transactions', array_filter([
            'group' => $row['uncategorised'] ? null : $row['groupId'],
            'categorised' => $row['uncategorised'] ? 'uncategorised' : null,
            'direction' => 'outgoing',
            'period' => 'custom',
            'from' => $monthFrom,
            'to' => $monthTo,
        ]));
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
    <div class="mf-out" wire:key="outflows-{{ $outflowsKey }}">
        <div class="mf-donut" x-data="outflowsDonut(@js($cashFlow->outflows), @js($cashFlow->spending))" role="img" aria-label="Donut chart of {{ count($cashFlow->outflows) }} outflow categories totalling {{ $formatMoney($cashFlow->spending) }}">
            <div x-ref="donut" wire:ignore></div>
        </div>

        <div class="mf-out-list">
            <div class="mf-out-head" aria-hidden="true">
                <span>Categories · {{ count($cashFlow->outflows) }}</span>
                <span>Value</span>
                <span>Weight</span>
            </div>
            <ul>
                @foreach ($cashFlow->outflows as $row)
                    @php($href = $outflowLink($row))
                    <li wire:key="outflow-{{ $row['id'] }}">
                        @if ($href !== null)
                            <a href="{{ $href }}" wire:navigate class="mf-out-row" data-testid="money-flow-outflow-row">
                                <i @style(['background: '.$row['color']]) aria-hidden="true"></i>
                                <span class="name">{{ $row['name'] }}</span>
                                <span class="val">{{ $formatMoney($row['amount']) }}</span>
                                <span class="pct">{{ number_format($row['percentage'], 1) }}%</span>
                            </a>
                        @else
                            <div class="mf-out-row" data-testid="money-flow-outflow-row">
                                <i @style(['background: '.$row['color']]) aria-hidden="true"></i>
                                <span class="name">{{ $row['name'] }}</span>
                                <span class="val">{{ $formatMoney($row['amount']) }}</span>
                                <span class="pct">{{ number_format($row['percentage'], 1) }}%</span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
