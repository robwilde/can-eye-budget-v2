<div class="space-y-6" data-testid="category-report">
    @php
        $summary = $this->summary;
        $chart = $this->chart;
        $treemap = $this->treemap;
        $unit = $mode === 'plan' ? 'planned entry' : 'transaction';
        $expenseRows = $this->expenseRows;
        $incomeRows = $this->incomeRows;
        $expenseView = $this->expenseView;
        $incomeView = $this->incomeView;
        $budget = $this->budgetPerformance;
        $change = static fn (?int $value): string => $value === null ? '' : ' · '.($value > 0 ? '+' : '').$value.'% vs previous period';
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Reports</flux:heading>
            <flux:text class="mt-1">Incoming and outgoing by category — {{ $periodLabel }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-1">
                <flux:button size="sm" variant="subtle" icon="chevron-left" wire:click="step(-1)" :disabled="! $canStep" aria-label="Previous period" data-testid="period-prev"/>
                <flux:button size="sm" variant="subtle" icon="chevron-right" wire:click="step(1)" :disabled="! $canStepForward" aria-label="Next period" data-testid="period-next"/>
            </div>
            <flux:button size="sm" variant="subtle" icon="arrow-down-tray" wire:click="exportCsv" data-testid="export-csv">Export CSV</flux:button>
            <flux:button size="sm" variant="subtle" icon="printer" :href="route('reports.print', $reportQuery)" target="_blank" data-testid="print-report">Print</flux:button>
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

    @if($rangeSwapped)
        <flux:callout variant="warning" icon="exclamation-triangle" data-testid="range-swapped">
            <flux:callout.heading>The end date was before the start date</flux:callout.heading>
            <flux:callout.text>The dates were swapped, so the report covers {{ $from }} to {{ $to }}.</flux:callout.text>
        </flux:callout>
    @endif

    <x-cib.filter-toggle
        :options="[
            ['value' => 'real', 'label' => 'Real', 'tone' => 'out'],
            ['value' => 'plan', 'label' => 'Plan', 'tone' => 'xfr'],
        ]"
        :selected="$mode"
        wire-model="mode"
    />

    @if($mode === 'real')
        <div class="flex flex-wrap items-center gap-3" data-testid="report-filters">
            <flux:select wire:model.live="account" size="sm" class="w-auto" aria-label="Account">
                <flux:select.option value="">All accounts</flux:select.option>
                @foreach($accounts as $accountOption)
                    <flux:select.option value="{{ $accountOption->id }}">{{ $accountOption->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="tag" size="sm" class="w-auto" aria-label="Tag">
                <flux:select.option value="">All tags</flux:select.option>
                @foreach($tags as $tagOption)
                    <flux:select.option value="{{ $tagOption->id }}">{{ $tagOption->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="w-28">
                <flux:input type="number" min="0" step="0.01" wire:model.live.debounce.500ms="min" size="sm" placeholder="Min $" aria-label="Minimum amount"/>
            </div>
            <div class="w-28">
                <flux:input type="number" min="0" step="0.01" wire:model.live.debounce.500ms="max" size="sm" placeholder="Max $" aria-label="Maximum amount"/>
            </div>
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-3">
        <x-cib.money-card
            label="Income"
            tone="available"
            :amount="$summary['in']"
            hint="{{ $formatMoney($summary['inPerMonth']) }}/mo across {{ $summary['months'] }} {{ \Illuminate\Support\Str::plural('month', $summary['months']) }}{{ $change($summary['inChange']) }}"
        />
        <x-cib.money-card
            label="Expenses"
            tone="owed"
            :amount="$summary['out']"
            hint="{{ $formatMoney($summary['outPerMonth']) }}/mo{{ $summary['outPctIncome'] !== null ? ' · '.$summary['outPctIncome'].'% of income' : '' }}{{ $change($summary['outChange']) }}"
        />
        <x-cib.money-card
            label="Net"
            :tone="$summary['net'] >= 0 ? 'available' : 'owed'"
            :amount="$summary['net']"
            hint="{{ $formatMoney($summary['netPerMonth']) }}/mo{{ $summary['netPctIncome'] !== null ? ' · '.$summary['netPctIncome'].'% of income' : '' }}{{ $change($summary['netChange']) }}"
        />
        @if($budget !== null)
            <x-cib.money-card
                label="Budget"
                :tone="$budget['spent'] > $budget['budget'] ? 'owed' : 'needed'"
                :amount="$budget['spent']"
                hint="{{ $budget['percent'] }}% of {{ $formatMoney($budget['budget']) }} budget used"
                data-testid="budget-performance"
            />
        @endif
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
        <flux:select wire:model.live="sort" size="sm" class="w-auto" aria-label="Sort by">
            <flux:select.option value="amount">Sort by amount</flux:select.option>
            <flux:select.option value="count">Sort by count</flux:select.option>
        </flux:select>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="agenda-group">
            <x-cib.sec-head title="Expenses"/>
            @if($expenseRows === [])
                <x-cib.empty-state icon="chart-pie" title="No expenses in this period" description="Try a different time frame."/>
            @else
                <div class="day-card">
                    @foreach($expenseView['rows'] as $bucket)
                        @include('livewire.partials.category-report-bucket', [
                            'bucket' => $bucket,
                            'tone' => 'out',
                            'directionParam' => 'outgoing',
                        ])
                    @endforeach
                </div>
                @include('livewire.partials.category-report-pager', ['view' => $expenseView, 'property' => 'expensePage'])
            @endif
        </section>

        <section class="agenda-group">
            <x-cib.sec-head title="Incomes"/>
            @if($incomeRows === [])
                <x-cib.empty-state icon="chart-pie" title="No income in this period" description="Try a different time frame."/>
            @else
                <div class="day-card">
                    @foreach($incomeView['rows'] as $bucket)
                        @include('livewire.partials.category-report-bucket', [
                            'bucket' => $bucket,
                            'tone' => 'inc',
                            'directionParam' => 'incoming',
                        ])
                    @endforeach
                </div>
                @include('livewire.partials.category-report-pager', ['view' => $incomeView, 'property' => 'incomePage'])
            @endif
        </section>
    </div>
</div>
