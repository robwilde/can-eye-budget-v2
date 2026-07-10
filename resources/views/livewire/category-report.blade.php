<div class="space-y-6" data-testid="category-report">
    @php
        $summary = $this->summary;
        $chart = $this->chart;
        $treemap = $this->treemap;
        $unit = $mode === 'plan' ? 'planned entry' : 'transaction';
        $expenseRows = $this->expenseRows;
        $incomeRows = $this->incomeRows;
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

    <x-cib.filter-toggle
        :options="[
            ['value' => 'real', 'label' => 'Real', 'tone' => 'out'],
            ['value' => 'plan', 'label' => 'Plan', 'tone' => 'xfr'],
        ]"
        :selected="$mode"
        wire-model="mode"
    />

    <div class="grid gap-3 sm:grid-cols-3">
        <x-cib.money-card
            label="Income"
            tone="available"
            :amount="$summary['in']"
            hint="{{ $formatMoney($summary['inPerMonth']) }}/mo across {{ $summary['months'] }} {{ \Illuminate\Support\Str::plural('month', $summary['months']) }}"
        />
        <x-cib.money-card
            label="Expenses"
            tone="owed"
            :amount="$summary['out']"
            hint="{{ $formatMoney($summary['outPerMonth']) }}/mo{{ $summary['outPctIncome'] !== null ? ' · '.$summary['outPctIncome'].'% of income' : '' }}"
        />
        <x-cib.money-card
            label="Net"
            :tone="$summary['net'] >= 0 ? 'available' : 'owed'"
            :amount="$summary['net']"
            hint="{{ $formatMoney($summary['netPerMonth']) }}/mo{{ $summary['netPctIncome'] !== null ? ' · '.$summary['netPctIncome'].'% of income' : '' }}"
        />
    </div>

    <div
        wire:ignore
        x-data="reportCharts(@js($chart), @js($treemap))"
        class="space-y-6"
    >
        <x-cib.card>
            <x-slot:header>
                <div class="sec-head"><h3>Monthly income & expenses</h3></div>
            </x-slot:header>
            <div x-ref="timeseries" class="report-chart"></div>
        </x-cib.card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-cib.card>
                <x-slot:header>
                    <div class="sec-head"><h3>Where it goes</h3></div>
                </x-slot:header>
                <div x-ref="expenseTree" class="report-treemap"></div>
            </x-cib.card>
            <x-cib.card>
                <x-slot:header>
                    <div class="sec-head"><h3>Where it comes from</h3></div>
                </x-slot:header>
                <div x-ref="incomeTree" class="report-treemap"></div>
            </x-cib.card>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="$toggle('topOnly')" @class(['report-chip', 'active' => $topOnly])>
            Top {{ $topLimit }}
        </button>
        <button type="button" wire:click="$toggle('showSubcategories')" @class(['report-chip', 'active' => $showSubcategories])>
            Subcategories
        </button>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="agenda-group">
            <x-cib.sec-head title="Expenses"/>
            @if($expenseRows === [])
                <x-cib.empty-state icon="chart-pie" title="No expenses in this period" description="Try a different time frame."/>
            @else
                <div class="day-card">
                    @foreach($expenseRows as $bucket)
                        @include('livewire.partials.category-report-bucket', [
                            'bucket' => $bucket,
                            'tone' => 'out',
                            'directionParam' => 'outgoing',
                        ])
                    @endforeach
                </div>
            @endif
        </section>

        <section class="agenda-group">
            <x-cib.sec-head title="Incomes"/>
            @if($incomeRows === [])
                <x-cib.empty-state icon="chart-pie" title="No income in this period" description="Try a different time frame."/>
            @else
                <div class="day-card">
                    @foreach($incomeRows as $bucket)
                        @include('livewire.partials.category-report-bucket', [
                            'bucket' => $bucket,
                            'tone' => 'inc',
                            'directionParam' => 'incoming',
                        ])
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
