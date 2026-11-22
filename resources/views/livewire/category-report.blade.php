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

    @foreach ($this->sections as $section)
        @continue($mode !== 'real' && $section !== \App\Enums\ReportSection::Spending)

        <section wire:key="report-section-{{ $section->value }}" x-data="{ open: true }" class="space-y-4" data-testid="report-section-{{ $section->value }}">
            <div class="flex items-center justify-between gap-2">
                <button type="button" class="flex items-center gap-2 font-bold" @click="open = ! open" :aria-expanded="open.toString()" aria-controls="report-section-body-{{ $section->value }}" data-testid="report-section-toggle-{{ $section->value }}">
                    <flux:icon.chevron-down variant="micro" x-show="open"/>
                    <flux:icon.chevron-right variant="micro" x-show="! open" x-cloak/>
                    {{ $section->label() }}
                </button>
                @if ($mode === 'real')
                    <div class="flex items-center gap-1">
                        <flux:button size="xs" variant="subtle" icon="chevron-up" wire:click="moveSection('{{ $section->value }}', 'up')" :disabled="$loop->first" aria-label="Move {{ $section->label() }} up" data-testid="report-section-up-{{ $section->value }}"/>
                        <flux:button size="xs" variant="subtle" icon="chevron-down" wire:click="moveSection('{{ $section->value }}', 'down')" :disabled="$loop->last" aria-label="Move {{ $section->label() }} down" data-testid="report-section-down-{{ $section->value }}"/>
                    </div>
                @endif
            </div>

            <div x-show="open" id="report-section-body-{{ $section->value }}" class="space-y-6">
                @include('livewire.partials.report-section-'.$section->value)
            </div>
        </section>
    @endforeach
</div>
