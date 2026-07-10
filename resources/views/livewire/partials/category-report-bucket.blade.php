@use('Illuminate\Support\Str')
@php
    $isExpanded = $showSubcategories || in_array($bucket['id'], $expanded, true);
    $countLabel = $bucket['count'].' '.Str::plural($unit, $bucket['count']);
    $leafHref = $bucket['id'] !== null
        ? route('transactions', array_filter([
            'category' => $bucket['id'],
            'period' => $period,
            'direction' => $directionParam,
            'from' => $from,
            'to' => $to,
        ]))
        : null;
@endphp
<div wire:key="bucket-{{ $tone }}-{{ $bucket['id'] ?? 'uncat' }}">
    @if($bucket['expandable'])
        <button type="button" class="report-row" wire:click="toggleExpand({{ $bucket['id'] }})" aria-expanded="{{ $isExpanded ? 'true' : 'false' }}">
            <div class="tx-ico {{ $tone }}">
                @if($bucket['icon'])<flux:icon :name="$bucket['icon']" variant="mini"/>@endif
            </div>
            <div class="report-name">
                <div class="tx-name">{{ $bucket['name'] }}</div>
                <div class="tx-meta">{{ $countLabel }}<span class="sm:hidden"> · {{ $bucket['pct'] }}% · {{ $formatMoney($bucket['perMonth']) }}/mo</span></div>
            </div>
            <div class="report-metric report-pct hidden sm:block">{{ $bucket['pct'] }}%</div>
            <div class="report-metric report-permo hidden sm:block">{{ $formatMoney($bucket['perMonth']) }}/mo</div>
            <div class="tx-amt {{ $tone }}">{{ $formatMoney($bucket['total']) }}</div>
            <flux:icon :name="$isExpanded ? 'minus' : 'plus'" class="size-4 shrink-0 text-fg-3"/>
        </button>
    @elseif($bucket['id'] !== null)
        <a href="{{ $leafHref }}" wire:navigate class="report-row">
            <div class="tx-ico {{ $tone }}">
                @if($bucket['icon'])<flux:icon :name="$bucket['icon']" variant="mini"/>@endif
            </div>
            <div class="report-name">
                <div class="tx-name">{{ $bucket['name'] }}</div>
                <div class="tx-meta">{{ $countLabel }}<span class="sm:hidden"> · {{ $bucket['pct'] }}% · {{ $formatMoney($bucket['perMonth']) }}/mo</span></div>
            </div>
            <div class="report-metric report-pct hidden sm:block">{{ $bucket['pct'] }}%</div>
            <div class="report-metric report-permo hidden sm:block">{{ $formatMoney($bucket['perMonth']) }}/mo</div>
            <div class="tx-amt {{ $tone }}">{{ $formatMoney($bucket['total']) }}</div>
            <flux:icon.arrow-up-right class="size-4 shrink-0 text-fg-3"/>
        </a>
    @else
        <div class="report-row passive">
            <div class="tx-ico {{ $tone }}"></div>
            <div class="report-name">
                <div class="tx-name">{{ $bucket['name'] }}</div>
                <div class="tx-meta">{{ $countLabel }}<span class="sm:hidden"> · {{ $bucket['pct'] }}% · {{ $formatMoney($bucket['perMonth']) }}/mo</span></div>
            </div>
            <div class="report-metric report-pct hidden sm:block">{{ $bucket['pct'] }}%</div>
            <div class="report-metric report-permo hidden sm:block">{{ $formatMoney($bucket['perMonth']) }}/mo</div>
            <div class="tx-amt {{ $tone }}">{{ $formatMoney($bucket['total']) }}</div>
            <span class="size-4 shrink-0"></span>
        </div>
    @endif

    <div class="track">
        <div class="fill" style="width: {{ $bucket['bar'] }}%; background-color: {{ $bucket['color'] }}"></div>
    </div>

    @if($isExpanded && $bucket['children'] !== [])
        <div class="report-children">
            @foreach($bucket['children'] as $child)
                <div wire:key="child-{{ $tone }}-{{ $child['id'] }}">
                    <a
                        href="{{ route('transactions', array_filter([
                            'category' => $child['id'],
                            'period' => $period,
                            'direction' => $directionParam,
                            'from' => $from,
                            'to' => $to,
                        ])) }}"
                        wire:navigate
                        class="report-row report-child-row"
                    >
                        <div class="report-name">
                            <div class="tx-name">{{ $child['path'] }}</div>
                            <div class="tx-meta">{{ $child['count'] }}<span class="sm:hidden"> · {{ $child['pct'] }}% · {{ $formatMoney($child['perMonth']) }}/mo</span></div>
                        </div>
                        <div class="report-metric report-pct hidden sm:block">{{ $child['pct'] }}%</div>
                        <div class="report-metric report-permo hidden sm:block">{{ $formatMoney($child['perMonth']) }}/mo</div>
                        <div class="tx-amt {{ $tone }}">{{ $formatMoney($child['total']) }}</div>
                        <span class="size-4 shrink-0"></span>
                    </a>
                    <div class="track track-sub">
                        <div class="fill" style="width: {{ $child['bar'] }}%; background-color: {{ $bucket['color'] }}"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
