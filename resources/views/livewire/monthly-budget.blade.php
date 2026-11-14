@use('App\Casts\MoneyCast')
@use('App\Enums\BudgetLineStatus')
@php
    $summary = $this->summary;
    $statusColors = [
        BudgetLineStatus::OverBudget->value => 'red',
        BudgetLineStatus::NearLimit->value => 'amber',
        BudgetLineStatus::OnTrack->value => 'green',
        BudgetLineStatus::UnbudgetedSpend->value => 'orange',
    ];
    $filters = [
        \App\Livewire\MonthlyBudget::FILTER_ALL => __('All'),
        \App\Livewire\MonthlyBudget::FILTER_ON_TRACK => __('On Track'),
        \App\Livewire\MonthlyBudget::FILTER_OVER => __('Over Budget'),
    ];
@endphp
<div class="space-y-6" data-testid="monthly-budget">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            @if ($this->previousMonth !== null)
                <a href="{{ route('budget', ['month' => $this->previousMonth]) }}" wire:navigate class="rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="{{ __('Previous month') }}" data-testid="previous-month">
                    <flux:icon name="chevron-left" class="size-5"/>
                </a>
            @endif
            <flux:heading size="xl" data-testid="budget-month">{{ $this->viewed->format('F Y') }}</flux:heading>
            @if ($this->nextMonth !== null)
                <a href="{{ route('budget', ['month' => $this->nextMonth]) }}" wire:navigate class="rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="{{ __('Next month') }}" data-testid="next-month">
                    <flux:icon name="chevron-right" class="size-5"/>
                </a>
            @endif
        </div>
        <flux:input type="month" wire:model.live="month" min="{{ $this->bounds['min']->format('Y-m') }}" max="{{ $this->bounds['max']->format('Y-m') }}" aria-label="{{ __('Choose a month') }}" class="max-w-44" data-testid="month-picker"/>
    </div>

    @error('budget')
        <flux:callout variant="danger" icon="exclamation-triangle" data-testid="budget-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if ($summary === null || ! $summary->initialised)
        <flux:card class="space-y-4" data-testid="budget-setup">
            <div>
                <flux:heading size="lg">{{ __('Set up :month', ['month' => $this->viewed->format('F Y')]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('Enter what you plan to spend and expect to earn. Each category starts at zero.') }}</flux:text>
            </div>

            @if ($this->copySource !== null && ! $startFresh)
                <div class="flex flex-wrap items-center gap-3">
                    <flux:button variant="primary" wire:click="copyFromPrevious" data-testid="copy-previous">
                        {{ __('Copy from :month', ['month' => $this->copySource->period_start->format('F Y')]) }}
                    </flux:button>
                    <flux:button variant="ghost" wire:click="$set('startFresh', true)" data-testid="start-fresh">{{ __('Start fresh') }}</flux:button>
                </div>
            @else
                <form wire:submit="initialise" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="totalInput" type="number" step="0.01" min="0" label="{{ __('Budgeted spending') }}" data-testid="total-input"/>
                        <flux:input wire:model="incomeInput" type="number" step="0.01" min="0" label="{{ __('Expected income') }}" data-testid="income-input"/>
                    </div>
                    @error('setup') <flux:text class="text-red-600 dark:text-red-500">{{ $message }}</flux:text> @enderror
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button type="submit" variant="primary" data-testid="save-setup">{{ __('Start budgeting') }}</flux:button>
                        <flux:button
                            type="button"
                            variant="ghost"
                            x-on:click="$wire.totalInput = '{{ number_format($this->suggestion['spending'] / 100, 2, '.', '') }}'; $wire.incomeInput = '{{ number_format($this->suggestion['income'] / 100, 2, '.', '') }}'"
                            data-testid="use-suggestion"
                        >
                            {{ __('Suggest from my history (:spending spending, :income income)', ['spending' => MoneyCast::format($this->suggestion['spending']), 'income' => MoneyCast::format($this->suggestion['income'])]) }}
                        </flux:button>
                    </div>
                </form>
            @endif
        </flux:card>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            <flux:card class="flex items-center justify-center" data-testid="budget-overview">
                @if ($summary->overAllocated)
                    <flux:callout variant="warning" icon="exclamation-triangle" data-testid="over-allocated-warning">
                        <flux:callout.heading>{{ __('Allocations are over budget') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __(':allocated is allocated against :total budgeted.', ['allocated' => MoneyCast::format($summary->allocated), 'total' => MoneyCast::format($summary->totalAmount)]) }}
                            <a href="#allocations" class="font-medium underline" data-testid="fix-allocations">{{ __('Fix allocations') }}</a>
                        </flux:callout.text>
                    </flux:callout>
                @else
                    @php
                        $percent = $summary->totalAmount > 0 ? min(100, intdiv($summary->spent * 100, $summary->totalAmount)) : ($summary->spent > 0 ? 100 : 0);
                    @endphp
                    <div class="relative size-44" data-testid="budget-donut">
                        <svg viewBox="0 0 36 36" class="size-44 -rotate-90" role="img" aria-label="{{ __(':percent% of the budget spent', ['percent' => $percent]) }}">
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="4" class="stroke-zinc-200 dark:stroke-zinc-700"/>
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="4" stroke-dasharray="{{ $percent }} {{ 100 - $percent }}" @class(['stroke-red-500' => $summary->spent > $summary->totalAmount, 'stroke-teal-500' => $summary->spent <= $summary->totalAmount])/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                            <span class="text-lg font-semibold">{{ MoneyCast::format($summary->spent) }}</span>
                            <span class="text-xs text-zinc-500">{{ __('of :total', ['total' => MoneyCast::format($summary->totalAmount)]) }}</span>
                        </div>
                    </div>
                @endif
            </flux:card>

            <flux:card class="space-y-4" data-testid="budget-figures">
                <div class="flex gap-2" role="group" aria-label="{{ __('Figures') }}">
                    <flux:button size="sm" :variant="$tab === \App\Livewire\MonthlyBudget::TAB_BUDGETED ? 'primary' : 'ghost'" wire:click="$set('tab', '{{ \App\Livewire\MonthlyBudget::TAB_BUDGETED }}')" aria-pressed="{{ $tab === \App\Livewire\MonthlyBudget::TAB_BUDGETED ? 'true' : 'false' }}" data-testid="tab-budgeted">{{ __('Budgeted') }}</flux:button>
                    <flux:button size="sm" :variant="$tab === \App\Livewire\MonthlyBudget::TAB_ACTUAL ? 'primary' : 'ghost'" wire:click="$set('tab', '{{ \App\Livewire\MonthlyBudget::TAB_ACTUAL }}')" aria-pressed="{{ $tab === \App\Livewire\MonthlyBudget::TAB_ACTUAL ? 'true' : 'false' }}" data-testid="tab-actual">{{ __('Actual') }}</flux:button>
                </div>

                @if ($tab === \App\Livewire\MonthlyBudget::TAB_ACTUAL)
                    <dl class="space-y-2" data-testid="actual-figures">
                        <div class="flex justify-between"><dt>{{ __('Income earned') }}</dt><dd>{{ MoneyCast::format($summary->actualIncome) }} <span class="text-zinc-500">({{ $summary->incomePercent }}%)</span></dd></div>
                        <div class="flex justify-between"><dt>{{ __('Spent') }}</dt><dd>{{ MoneyCast::format($summary->spent) }}</dd></div>
                    </dl>
                @else
                    <dl class="space-y-2" data-testid="budgeted-figures">
                        <div class="flex justify-between"><dt>{{ __('Expected income') }}</dt><dd>{{ MoneyCast::format($summary->expectedIncome) }}</dd></div>
                        <div class="flex justify-between"><dt>{{ __('Budgeted spending') }}</dt><dd>{{ MoneyCast::format($summary->totalAmount) }}</dd></div>
                        <div class="flex justify-between"><dt>{{ __('Allocated') }}</dt><dd>{{ MoneyCast::format($summary->allocated) }}</dd></div>
                        <div class="flex justify-between"><dt>{{ __('Available to allocate') }}</dt><dd data-testid="available-to-allocate">{{ MoneyCast::format($summary->availableToAllocate) }}</dd></div>
                    </dl>
                    <form wire:submit="initialise" class="grid gap-3 border-t border-neutral-200 pt-4 sm:grid-cols-3 dark:border-neutral-700">
                        <flux:input wire:model="totalInput" type="number" step="0.01" min="0" size="sm" label="{{ __('Budgeted spending') }}"/>
                        <flux:input wire:model="incomeInput" type="number" step="0.01" min="0" size="sm" label="{{ __('Expected income') }}"/>
                        <div class="flex items-end"><flux:button type="submit" size="sm" variant="filled">{{ __('Save') }}</flux:button></div>
                    </form>
                    @error('setup') <flux:text class="text-red-600 dark:text-red-500">{{ $message }}</flux:text> @enderror
                @endif
            </flux:card>
        </div>

        @if ($this->drilldown !== null)
            @php($detail = $this->drilldown)
            <flux:card class="space-y-4" data-testid="budget-drilldown">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">{{ $detail->name }}</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('drilldownId', null)" aria-label="{{ __('Close') }}"/>
                </div>
                <dl class="grid gap-2 sm:grid-cols-2">
                    <div class="flex justify-between"><dt>{{ __('Budgeted') }}</dt><dd>{{ $detail->allocation === null ? __('Shared') : MoneyCast::format($detail->allocation) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Rolled over') }}</dt><dd>{{ MoneyCast::format($detail->rolledOver) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Spent') }}</dt><dd>{{ MoneyCast::format($detail->actual) }}</dd></div>
                    @if ($detail->available !== null)
                        <div class="flex justify-between"><dt>{{ $detail->available < 0 ? __('Overspent') : __('Left') }}</dt><dd data-testid="drilldown-left">{{ MoneyCast::format(abs($detail->available)) }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt>{{ __('Monthly average') }}</dt><dd>{{ MoneyCast::format($detail->averageMonthlySpend ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Monthly median') }}</dt><dd>{{ MoneyCast::format($detail->medianMonthlySpend ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ __('Bills reserved') }}</dt><dd>{{ MoneyCast::format($detail->billsReserved ?? 0) }}</dd></div>
                    @if ($detail->suggestedDailySpend !== null)
                        <div class="flex justify-between"><dt>{{ __('Suggested daily spend') }}</dt><dd data-testid="suggested-daily">{{ MoneyCast::format($detail->suggestedDailySpend) }}</dd></div>
                    @endif
                </dl>
                <div>
                    <flux:heading size="sm">{{ __('Recent transactions') }}</flux:heading>
                    @forelse ($detail->recentTransactions ?? [] as $transaction)
                        <div class="flex justify-between border-b border-neutral-100 py-2 text-sm last:border-0 dark:border-neutral-800" wire:key="drilldown-tx-{{ $transaction->id }}">
                            <span>{{ $transaction->post_date->format('j M') }} · {{ $transaction->description }}</span>
                            <span>{{ $transaction->currency === auth()->user()->base_currency ? MoneyCast::format(abs($transaction->amount)) : $transaction->currency.' '.number_format(abs($transaction->amount) / 100, 2) }}</span>
                        </div>
                    @empty
                        <flux:text size="sm" class="text-zinc-500">{{ __('No transactions this month.') }}</flux:text>
                    @endforelse
                </div>
            </flux:card>
        @endif

        <flux:card class="space-y-4" id="allocations" data-testid="budget-categories">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Categories') }}</flux:heading>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($this->hasOverBudget)
                        <div class="flex gap-1" role="group" data-testid="status-filter">
                            @foreach ($filters as $value => $label)
                                <flux:button size="sm" :variant="$filter === $value ? 'primary' : 'ghost'" wire:click="$set('filter', '{{ $value }}')" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" data-testid="filter-{{ $value }}">{{ $label }}</flux:button>
                            @endforeach
                        </div>
                    @endif
                    <flux:button size="sm" variant="filled" icon="arrows-right-left" wire:click="$set('showMoveModal', true)" data-testid="open-move-money">{{ __('Move money') }}</flux:button>
                </div>
            </div>

            @foreach ($this->rows as $row)
                @php($line = $row['line'])
                <div wire:key="line-{{ $line->budgetId }}" class="grid items-center gap-3 border-b border-neutral-100 py-2 last:border-0 sm:grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_auto] dark:border-neutral-800" data-testid="budget-line" data-category="{{ $line->categoryId }}" data-status="{{ $line->status->value }}">
                    <div class="min-w-0" style="padding-left: {{ $row['depth'] * 1.5 }}rem">
                        <div class="flex items-center gap-2">
                            <span class="truncate font-medium">{{ $line->name }}</span>
                            @if ($line->isShared())
                                <flux:badge size="sm" color="zinc">{{ __('Shared') }}</flux:badge>
                            @else
                                <flux:badge size="sm" :color="$statusColors[$line->status->value]">{{ $line->status->label() }}</flux:badge>
                            @endif
                        </div>
                        @if ($line->percentSpent !== null)
                            <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                <div @class(['h-full rounded-full', 'bg-red-500' => $line->status === BudgetLineStatus::OverBudget, 'bg-amber-500' => $line->status === BudgetLineStatus::NearLimit, 'bg-teal-500' => in_array($line->status, [BudgetLineStatus::OnTrack, BudgetLineStatus::UnbudgetedSpend], true)]) style="width: {{ min(100, $line->percentSpent) }}%"></div>
                            </div>
                        @endif
                    </div>
                    <input
                        type="number" step="0.01" min="0"
                        value="{{ $line->allocation === null ? '' : number_format($line->allocation / 100, 2, '.', '') }}"
                        placeholder="{{ $line->isShared() ? __('Shared') : '0.00' }}"
                        wire:change="setAllocation({{ $line->budgetId }}, $event.target.value)"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-2 py-1 text-right text-sm dark:border-zinc-700 dark:bg-zinc-900"
                        aria-label="{{ __('Allocation for :name', ['name' => $line->name]) }}"
                        data-testid="allocation-input"
                    />
                    <span class="text-right text-sm" data-testid="line-spent"><span class="sr-only">{{ __('Spent') }}: </span>{{ MoneyCast::format($line->actual) }}</span>
                    <span class="text-right text-sm {{ $line->available !== null && $line->available < 0 ? 'text-red-600 dark:text-red-500' : '' }}" data-testid="line-available"><span class="sr-only">{{ __('Available') }}: </span>{{ $line->available === null ? '–' : MoneyCast::format($line->available) }}</span>
                    <div class="flex items-center justify-end gap-2">
                        <label class="flex items-center gap-1 text-xs text-zinc-500">
                            <input type="checkbox" @checked($line->rolloverEnabled) wire:change="setRollover({{ $line->budgetId }}, $event.target.checked)" aria-label="{{ __('Roll over :name', ['name' => $line->name]) }}" data-testid="rollover-toggle"/>
                            {{ __('Rollover') }}
                        </label>
                        <flux:button size="xs" variant="ghost" wire:click="$set('drilldownId', {{ $line->budgetId }})" data-testid="open-drilldown">{{ __('Details') }}</flux:button>
                    </div>
                </div>
            @endforeach

            @if ($summary->availableToAllocate > 0)
                <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800" data-testid="uncategorized-remainder">
                    <span class="font-medium">{{ __('Uncategorized') }}</span>
                    <span>{{ MoneyCast::format($summary->availableToAllocate) }}</span>
                </div>
            @endif
        </flux:card>

        <flux:modal wire:model="showMoveModal" class="md:w-96">
            <form wire:submit="moveMoney" class="space-y-6" data-testid="move-money-form">
                <div>
                    <flux:heading size="lg">{{ __('Move money') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Move budgeted money between two categories. The total stays the same.') }}</flux:text>
                </div>
                <flux:select wire:model="moveFromId" label="{{ __('From') }}" data-testid="move-from">
                    <flux:select.option value="">{{ __('Choose a category') }}</flux:select.option>
                    @foreach ($summary->lines as $option)
                        <flux:select.option value="{{ $option->budgetId }}" wire:key="from-{{ $option->budgetId }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="moveToId" label="{{ __('To') }}" data-testid="move-to">
                    <flux:select.option value="">{{ __('Choose a category') }}</flux:select.option>
                    @foreach ($summary->lines as $option)
                        <flux:select.option value="{{ $option->budgetId }}" wire:key="to-{{ $option->budgetId }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="moveAmount" type="number" step="0.01" min="0" label="{{ __('Amount') }}" data-testid="move-amount"/>
                @error('move') <flux:text class="text-red-600 dark:text-red-500" data-testid="move-error">{{ $message }}</flux:text> @enderror
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-testid="move-submit">{{ __('Move') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
