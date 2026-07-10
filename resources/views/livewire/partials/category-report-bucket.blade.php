@use('Illuminate\Support\Str')
@php
    $isExpanded = $showSubcategories || in_array($bucket['id'], $expanded, true);
    $meta = $bucket['count'].' '.Str::plural($unit, $bucket['count']).' · '.$bucket['pct'].'% · '.$formatMoney($bucket['perMonth']).'/mo';
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
        <button type="button" class="tx-row" wire:click="toggleExpand({{ $bucket['id'] }})" aria-expanded="{{ $isExpanded ? 'true' : 'false' }}">
            <div class="tx-ico {{ $tone }}">
                @if($bucket['icon'])<flux:icon :name="$bucket['icon']" variant="mini"/>@endif
            </div>
            <div>
                <div class="tx-name">{{ $bucket['name'] }}</div>
                <div class="tx-meta">{{ $meta }}</div>
            </div>
            <div class="tx-amt {{ $tone }}">{{ $formatMoney($bucket['total']) }}</div>
            <flux:icon :name="$isExpanded ? 'minus' : 'plus'" class="size-4 text-fg-3"/>
        </button>
    @elseif($bucket['id'] !== null)
        <a href="{{ $leafHref }}" wire:navigate class="tx-row">
            <div class="tx-ico {{ $tone }}">
                @if($bucket['icon'])<flux:icon :name="$bucket['icon']" variant="mini"/>@endif
            </div>
            <div>
                <div class="tx-name">{{ $bucket['name'] }}</div>
                <div class="tx-meta">{{ $meta }}</div>
            </div>
            <div class="tx-amt {{ $tone }}">{{ $formatMoney($bucket['total']) }}</div>
            <flux:icon.arrow-up-right class="size-4 text-fg-3"/>
        </a>
    @else
        <div class="tx-row passive">
            <div class="tx-ico {{ $tone }}"></div>
            <div>
                <div class="tx-name">{{ $bucket['name'] }}</div>
                <div class="tx-meta">{{ $meta }}</div>
            </div>
            <div class="tx-amt {{ $tone }}">{{ $formatMoney($bucket['total']) }}</div>
            <span></span>
        </div>
    @endif

    <div class="track">
        <div class="fill" style="width: {{ $bucket['bar'] }}%; background-color: {{ $bucket['color'] }}"></div>
    </div>

    @if($isExpanded && $bucket['children'] !== [])
        <div class="report-children">
            @foreach($bucket['children'] as $child)
                <a
                    href="{{ route('transactions', array_filter([
                        'category' => $child['id'],
                        'period' => $period,
                        'direction' => $directionParam,
                        'from' => $from,
                        'to' => $to,
                    ])) }}"
                    wire:navigate
                    wire:key="child-{{ $tone }}-{{ $child['id'] }}"
                    class="report-child"
                >
                    <div>
                        <div class="tx-name">{{ $child['path'] }}</div>
                        <div class="tx-meta">{{ $child['count'] }} · {{ $child['pct'] }}% · {{ $formatMoney($child['perMonth']) }}/mo</div>
                    </div>
                    <div class="tx-amt {{ $tone }}">{{ $formatMoney($child['total']) }}</div>
                </a>
            @endforeach
        </div>
    @endif
</div>
