@use('App\Casts\MoneyCast')
@use('App\Enums\GoalHealth')
@use('App\Enums\GoalStatus')
@php
    $summary = $this->overview['summary'];
    $healthLabels = [
        GoalHealth::Reached->value => [__('Reached'), 'green'],
        GoalHealth::NoTargetDate->value => [__('No target date'), 'zinc'],
        GoalHealth::OnTrack->value => [__('On track'), 'green'],
        GoalHealth::Behind->value => [__('Behind'), 'red'],
        GoalHealth::Funded->value => [__('Funded'), 'green'],
        GoalHealth::Depleted->value => [__('Depleted'), 'red'],
    ];
    $filters = [
        \App\Livewire\GoalList::FILTER_ALL => __('All'),
        \App\Livewire\GoalList::FILTER_ACTIVE => __('Active'),
        \App\Livewire\GoalList::FILTER_PAUSED => __('Paused'),
        \App\Livewire\GoalList::FILTER_COMPLETED => __('Completed'),
    ];
@endphp
<div class="space-y-6" data-testid="goal-list">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Goals') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="startCreate" data-testid="new-goal">{{ __('New goal') }}</flux:button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="goal-summary">
        <flux:card>
            <flux:text size="sm">{{ __('Savings inflow, last 30 days') }}</flux:text>
            <div class="text-xl font-semibold" data-testid="summary-inflow">{{ MoneyCast::format($summary['inflow']) }}</div>
            <flux:text size="sm" class="text-zinc-500">{{ __('Prior 30 days: :amount', ['amount' => MoneyCast::format($summary['priorInflow'])]) }}</flux:text>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('On track') }}</flux:text>
            <div class="text-xl font-semibold" data-testid="summary-on-track">{{ $summary['onTrack'] }}</div>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Behind') }}</flux:text>
            <div class="text-xl font-semibold" data-testid="summary-behind">{{ $summary['behind'] }}</div>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Needed per month') }}</flux:text>
            <div class="text-xl font-semibold" data-testid="summary-needed">{{ MoneyCast::format($summary['neededPerMonth']) }}</div>
        </flux:card>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex gap-1" role="group" aria-label="{{ __('Filter goals') }}" data-testid="goal-filters">
            @foreach ($filters as $value => $label)
                <flux:button size="sm" :variant="$filter === $value ? 'primary' : 'ghost'" wire:click="$set('filter', '{{ $value }}')" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" data-testid="goal-filter-{{ $value }}">{{ $label }}</flux:button>
            @endforeach
        </div>
        @if ($this->searchable)
            <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass" placeholder="{{ __('Search goals') }}" class="max-w-xs" data-testid="goal-search"/>
        @endif
    </div>

    @forelse ($this->rows as $row)
        @php
            $goal = $row['goal'];
            $progress = $row['progress'];
            [$healthLabel, $healthColor] = $goal->status === GoalStatus::Paused ? [__('Paused'), 'zinc'] : $healthLabels[$progress->health->value];
        @endphp
        <flux:card wire:key="goal-{{ $goal->id }}" class="space-y-3" data-testid="goal-row" data-status="{{ $goal->status->value }}" data-health="{{ $progress->health->value }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 items-center gap-2">
                    @if ($goal->colour)
                        <span class="inline-block size-3 shrink-0 rounded-full" style="background-color: {{ $goal->colour }}"></span>
                    @endif
                    <a href="{{ route('goals.show', $goal->id) }}" wire:navigate class="truncate font-medium hover:underline">{{ $goal->name }}</a>
                    <flux:badge size="sm" :color="$healthColor">{{ $healthLabel }}</flux:badge>
                </div>
                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $goal->id }})" aria-label="{{ __('Edit :name', ['name' => $goal->name]) }}"/>
            </div>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-full rounded-full bg-teal-500" style="width: {{ $progress->percent }}%"></div>
            </div>
            <div class="flex flex-wrap justify-between gap-2 text-sm">
                <span>{{ MoneyCast::format($progress->savedAmount) }} / {{ MoneyCast::format($progress->targetAmount) }} ({{ $progress->percent }}%)</span>
                @if ($progress->monthlyTarget !== null && $progress->remainingAmount > 0)
                    <span class="text-zinc-500">{{ __(':amount a month needed', ['amount' => MoneyCast::format($progress->monthlyTarget)]) }}</span>
                @endif
            </div>
        </flux:card>
    @empty
        <flux:text class="text-zinc-500" data-testid="goals-empty">{{ __('No goals match.') }}</flux:text>
    @endforelse

    @if ($this->archivedRows !== [])
        <details class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700" data-testid="archived-goals">
            <summary class="cursor-pointer font-medium">{{ __('Archived (:count)', ['count' => count($this->archivedRows)]) }}</summary>
            <div class="mt-3 space-y-2">
                @foreach ($this->archivedRows as $row)
                    <div wire:key="archived-{{ $row['goal']->id }}" class="flex justify-between text-sm">
                        <a href="{{ route('goals.show', $row['goal']->id) }}" wire:navigate class="hover:underline">{{ $row['goal']->name }}</a>
                        <span>{{ MoneyCast::format($row['progress']->savedAmount) }} / {{ MoneyCast::format($row['progress']->targetAmount) }}</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    <flux:modal wire:model="showForm" class="md:w-xl">
        <form wire:submit="save" class="space-y-4" data-testid="goal-form">
            <flux:heading size="lg">{{ $editingId === null ? __('New goal') : __('Edit goal') }}</flux:heading>
            <flux:input wire:model="name" label="{{ __('Name') }}" data-testid="goal-name"/>
            <flux:select wire:model.live="kind" label="{{ __('Kind') }}" data-testid="goal-kind">
                <flux:select.option value="one_off">{{ __('One-off goal') }}</flux:select.option>
                <flux:select.option value="reserve">{{ __('Maintained reserve') }}</flux:select.option>
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="targetAmount" type="number" step="0.01" min="0" label="{{ $kind === 'reserve' ? __('Floor to hold') : __('Target amount') }}" data-testid="goal-target"/>
                @if ($kind === 'reserve')
                    <flux:input wire:model="monthsOfExpenses" type="number" min="1" label="{{ __('Or months of expenses (optional)') }}" data-testid="goal-months"/>
                @else
                    <flux:input wire:model="targetDate" type="date" label="{{ __('Target date (optional)') }}" data-testid="goal-date"/>
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="colour" label="{{ __('Colour (hex, optional)') }}" placeholder="#14b8a6"/>
                <flux:input wire:model="icon" label="{{ __('Icon (optional)') }}"/>
            </div>
            <flux:textarea wire:model="notes" label="{{ __('Notes (optional)') }}"/>
            <fieldset class="space-y-2" data-testid="goal-accounts">
                <legend class="text-sm font-medium">{{ __('Funding accounts') }}</legend>
                @foreach ($this->accountOptions as $account)
                    <div wire:key="account-{{ $account->id }}" class="flex items-center gap-3">
                        <label class="flex flex-1 items-center gap-2 text-sm">
                            <input type="checkbox" wire:model.live="accountIds" value="{{ $account->id }}"/>
                            {{ $account->name }} <span class="text-zinc-500">({{ $account->currency }})</span>
                        </label>
                        @if (in_array((string) $account->id, $accountIds, true))
                            <input type="number" step="0.01" min="0" wire:model="earmarks.{{ $account->id }}" placeholder="{{ __('Whole balance') }}" class="w-36 rounded-lg border border-zinc-200 bg-white px-2 py-1 text-right text-sm dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('Earmark for :name', ['name' => $account->name]) }}"/>
                        @endif
                    </div>
                @endforeach
            </fieldset>
            @error('goal') <flux:text class="text-red-600 dark:text-red-500" data-testid="goal-error">{{ $message }}</flux:text> @enderror
            @foreach (['name', 'targetAmount', 'targetDate', 'monthsOfExpenses'] as $field)
                @error($field) <flux:text class="text-red-600 dark:text-red-500">{{ $message }}</flux:text> @enderror
            @endforeach
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="cancelForm">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-testid="goal-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
