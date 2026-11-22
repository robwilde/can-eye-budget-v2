@use('App\Casts\MoneyCast')

@props(['cashFlow', 'scope', 'from', 'to', 'label' => 'Categories'])

@php
    $outflowsKey = $scope.'-'.md5((string) json_encode($cashFlow->outflows));
    $outflowLink = static fn (array $row): ?string => $row['groupId'] === null && ! $row['uncategorised']
        ? null
        : route('transactions', array_filter([
            'group' => $row['uncategorised'] ? null : $row['groupId'],
            'categorised' => $row['uncategorised'] ? 'uncategorised' : null,
            'direction' => 'outgoing',
            'period' => 'custom',
            'from' => $from,
            'to' => $to,
        ]));
@endphp
<div class="mf-out" wire:key="outflows-{{ $outflowsKey }}">
    <div class="mf-donut" x-data="outflowsDonut(@js($cashFlow->outflows), @js($cashFlow->spending))" role="img" aria-label="Donut chart of {{ count($cashFlow->outflows) }} outflows totalling {{ MoneyCast::format($cashFlow->spending) }}">
        <div x-ref="donut" wire:ignore></div>
    </div>

    <div class="mf-out-list">
        <div class="mf-out-head" aria-hidden="true">
            <span>{{ $label }} · {{ count($cashFlow->outflows) }}</span>
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
                            <span class="val">{{ MoneyCast::format($row['amount']) }}</span>
                            <span class="pct">{{ number_format($row['percentage'], 1) }}%</span>
                        </a>
                    @else
                        <div class="mf-out-row" data-testid="money-flow-outflow-row">
                            <i @style(['background: '.$row['color']]) aria-hidden="true"></i>
                            <span class="name">{{ $row['name'] }}</span>
                            <span class="val">{{ MoneyCast::format($row['amount']) }}</span>
                            <span class="pct">{{ number_format($row['percentage'], 1) }}%</span>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</div>
