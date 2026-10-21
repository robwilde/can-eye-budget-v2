@php
    $mf = $monthFlow;
    $monthLabel = $this->headerLabel['label'];
    $signed = static fn (int $cents): string => ($cents < 0 ? '−' : '+').$formatMoney(abs($cents));
@endphp
<header class="mf-head">
    <h2 class="mf-verdict" data-testid="money-flow-verdict">
        @if ($mf->isEmpty())
            Nothing planned or entered for {{ $monthLabel }} yet.
        @elseif ($mf->projectedNet() >= 0)
            Yes — you're clear. <em class="is-ok">{{ $formatMoney($mf->projectedNet()) }}</em> left after everything still to come.
        @else
            Careful — you're <em class="is-short">{{ $formatMoney(abs($mf->projectedNet())) }}</em> short once upcoming bills land.
        @endif
    </h2>
    <p class="mf-sub">Ordinary income and spend only, transfers excluded. Every figure is net by category: a refund inside a category reduces that category rather than counting as income. Plan is the month's full schedule, reconciled or not. Missed planned bills stay in the plan and are simply absent from actual.</p>
</header>

<div class="mf-pa">
    <div class="mf-card mf-card-plan" data-testid="money-flow-plan">
        <h3>Plan</h3>
        <dl>
            <div><dt>Incomes</dt><dd class="pos">{{ $signed($mf->planIncome) }}</dd></div>
            <div><dt>Expenses</dt><dd class="neg">{{ $signed(-$mf->planExpense) }}</dd></div>
            <div><dt>Net</dt><dd class="{{ $mf->planNet() >= 0 ? 'pos' : 'neg' }}">{{ $signed($mf->planNet()) }}</dd></div>
        </dl>
        @if ($mf->planSavedPercent() !== null)
            <p class="mf-saved"><b>{{ $mf->planSavedPercent() }}%</b> planned to save</p>
        @endif
    </div>

    <div class="mf-card mf-card-actual" data-testid="money-flow-actual">
        <h3>Actual</h3>
        <dl>
            <div><dt>Incomes</dt><dd class="pos">{{ $signed($mf->actualIncome) }}</dd></div>
            <div><dt>Expenses</dt><dd class="neg">{{ $signed(-$mf->actualExpense) }}</dd></div>
            <div><dt>Net</dt><dd class="{{ $mf->actualNet() >= 0 ? 'pos' : 'neg' }}">{{ $signed($mf->actualNet()) }}</dd></div>
        </dl>
        @if ($mf->actualSavedPercent() !== null)
            <p class="mf-saved"><b>{{ $mf->actualSavedPercent() }}%</b> saved</p>
        @endif
    </div>

    <div class="mf-bars">
        @include('livewire.calendar-view.plan-vs-actual-bar', [
            'barKey' => 'income',
            'barLabel' => 'Income',
            'verb' => 'received',
            'plan' => $mf->planIncome,
            'actual' => $mf->actualIncome,
            'plannedToDate' => $mf->plannedIncomeToDate(),
            'over' => false,
        ])
        @include('livewire.calendar-view.plan-vs-actual-bar', [
            'barKey' => 'expense',
            'barLabel' => 'Expenses',
            'verb' => 'spent',
            'plan' => $mf->planExpense,
            'actual' => $mf->actualExpense,
            'plannedToDate' => $mf->plannedExpenseToDate(),
            'over' => $mf->actualExpense > $mf->planExpense,
        ])
        @if ($mf->todayLabel !== null)
            <p class="mf-note">Dashed line marks today ({{ $mf->todayLabel }}), {{ $mf->elapsedPercent() }}% of the month elapsed.</p>
        @endif
    </div>
</div>
