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
