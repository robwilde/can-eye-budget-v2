@use('Illuminate\Support\Str')
<div class="tx-ico {{ $tone }}">
    @if($bucket['icon'])
        <flux:icon :name="$bucket['icon']" variant="mini"/>
    @endif
</div>
<div>
    <div class="tx-name">{{ $bucket['name'] }}</div>
    <div class="tx-meta">{{ $bucket['count'] }} {{ Str::plural('transaction', $bucket['count']) }}</div>
</div>
<div class="tx-amt {{ $tone }}">{{ $formatMoney($bucket['total']) }}</div>
