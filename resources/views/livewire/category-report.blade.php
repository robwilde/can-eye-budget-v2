<div class="space-y-6" data-testid="category-report">
    @php
        $summary = $this->summary;
        $report = $this->report;
        $net = $summary['net'];
        $tone = $direction === 'outgoing' ? 'out' : 'inc';
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Reports</flux:heading>
            <flux:text class="mt-1">Incoming and outgoing by category — {{ $periodLabel }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="period" size="sm" class="w-auto">
                <flux:select.option value="7d">Last 7 Days</flux:select.option>
                <flux:select.option value="this-month">This Month</flux:select.option>
                <flux:select.option value="3m">Last 3 Months</flux:select.option>
                <flux:select.option value="6m">Last 6 Months</flux:select.option>
                <flux:select.option value="1y">Last Year</flux:select.option>
                @if($hasPayCycle)
                    <flux:select.option value="pay-cycle">Pay Cycle</flux:select.option>
                @endif
                <flux:select.option value="all">All Time</flux:select.option>
                <flux:select.option value="custom">Custom Range</flux:select.option>
            </flux:select>
            @if($showCustomRange)
                <flux:input type="date" wire:model.live="from" size="sm" class="w-auto"/>
                <flux:input type="date" wire:model.live="to" size="sm" class="w-auto"/>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-cib.stat-pill tone="income">+{{ $formatMoney($summary['in']) }} in</x-cib.stat-pill>
        <x-cib.stat-pill tone="posted">−{{ $formatMoney($summary['out']) }} out</x-cib.stat-pill>
        <x-cib.stat-pill :tone="$net >= 0 ? 'buffer-pos' : 'buffer-neg'">
            {{ $net >= 0 ? '+' : '−' }}{{ $formatMoney(abs($net)) }} net
        </x-cib.stat-pill>
    </div>

    <x-cib.filter-toggle
        :options="[
            ['value' => 'outgoing', 'label' => 'Outgoing', 'tone' => 'out'],
            ['value' => 'incoming', 'label' => 'Incoming', 'tone' => 'inc'],
        ]"
        :selected="$direction"
        wire-model="direction"
    />

    @if($parent !== null)
        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" size="sm" wire:click="drillUp" aria-label="Back"/>
            <div class="flex flex-wrap items-center gap-1 text-sm">
                <button type="button" wire:click="$set('parent', null)" class="font-bold text-cib-teal-500">All categories</button>
                @foreach($this->breadcrumb as $crumb)
                    <span class="text-fg-3">/</span>
                    @if($loop->last)
                        <span class="font-bold">{{ $crumb['name'] }}</span>
                    @else
                        <button type="button" wire:click="drillInto({{ $crumb['id'] }})" class="font-bold text-cib-teal-500">{{ $crumb['name'] }}</button>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    @if($report['buckets'] === [])
        <x-cib.empty-state icon="chart-pie" title="No transactions in this period" description="Try a different time frame or direction."/>
    @else
        <section class="agenda-group">
            <div class="day-card">
                @foreach($report['buckets'] as $bucket)
                    @php
                        $pct = $report['max'] > 0 ? min(100, max(0, round($bucket['total'] / $report['max'] * 100))) : 0;
                        $leafHref = $bucket['id'] !== null
                            ? route('transactions', array_filter([
                                'category' => $bucket['id'],
                                'period' => $period,
                                'direction' => $direction,
                                'from' => $from,
                                'to' => $to,
                            ]))
                            : null;
                    @endphp
                    <div wire:key="bucket-{{ $bucket['id'] ?? 'special' }}">
                        @if($bucket['drillable'])
                            <button type="button" class="tx-row" wire:click="drillInto({{ $bucket['id'] }})">
                                @include('livewire.partials.category-report-row')
                                <flux:icon.chevron-right class="size-4 text-fg-3"/>
                            </button>
                        @elseif($bucket['id'] !== null)
                            <a href="{{ $leafHref }}" wire:navigate class="tx-row">
                                @include('livewire.partials.category-report-row')
                                <flux:icon.arrow-up-right class="size-4 text-fg-3"/>
                            </a>
                        @else
                            <div class="tx-row passive">
                                @include('livewire.partials.category-report-row')
                            </div>
                        @endif
                        <div class="track">
                            <div class="fill" style="width: {{ $pct }}%; background-color: {{ $bucket['color'] }}"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
