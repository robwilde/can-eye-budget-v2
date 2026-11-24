@use('App\Casts\MoneyCast')
@use('App\Enums\OccurrenceState')
@use('App\Enums\PlannedTransactionType')
@php
    $overview = $this->overview;
    $kpis = $overview->kpis;
    $empty = ! $this->hasBills;
    $sections = [
        'past-due' => [__('Past due'), $overview->pastDue],
        'this-month' => [__('This month'), $overview->thisMonth],
        'later' => [__('Later'), $overview->later],
        'dormant' => [__('Paused'), $overview->dormant],
    ];
    $stateColor = fn (OccurrenceState $state): string => match ($state) {
        OccurrenceState::Overdue => 'red',
        OccurrenceState::Due => 'amber',
        OccurrenceState::PartiallyPaid => 'amber',
        OccurrenceState::Paid => 'green',
        OccurrenceState::Dormant => 'yellow',
        default => 'zinc',
    };
@endphp
<div class="space-y-6" data-testid="bills-page">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Bills') }}</flux:heading>
        <div class="flex gap-2">
            <flux:button variant="primary" size="sm" wire:click="$dispatch('declare-plan', { kind: 'bill' })" data-testid="add-bill">{{ __('Add bill') }}</flux:button>
            <flux:button size="sm" wire:click="$dispatch('declare-plan', { kind: 'income' })" data-testid="add-income">{{ __('Add income') }}</flux:button>
        </div>
    </div>

    @if($this->firstVisitCount > 0)
        <flux:callout icon="sparkles" data-testid="first-visit-prompt">
            <flux:callout.heading>{{ trans_choice(':count detected bill to review|:count detected bills to review', $this->firstVisitCount, ['count' => $this->firstVisitCount]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('We found recurring payments in your transactions. Confirm the ones that are real bills.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button href="{{ route('rules') }}" wire:navigate size="sm">{{ __('Review') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @if($notice !== '')
        <flux:text data-testid="bills-notice" role="status">{{ $notice }}</flux:text>
    @endif

    <div class="flex gap-2" role="tablist" aria-label="{{ __('Bills views') }}" data-testid="bills-tabs">
        <flux:button size="sm" :variant="$tab === 'overview' ? 'primary' : 'ghost'" wire:click="showTab('overview')" role="tab" :aria-selected="$tab === 'overview' ? 'true' : 'false'" data-testid="tab-overview">{{ __('Overview') }}</flux:button>
        <flux:button size="sm" :variant="$tab === 'all' ? 'primary' : 'ghost'" wire:click="showTab('all')" role="tab" :aria-selected="$tab === 'all' ? 'true' : 'false'" data-testid="tab-all">{{ __('All bills') }}</flux:button>
        <flux:button size="sm" :variant="$tab === 'calendar' ? 'primary' : 'ghost'" wire:click="showTab('calendar')" role="tab" :aria-selected="$tab === 'calendar' ? 'true' : 'false'" data-testid="tab-calendar">{{ __('Calendar') }}</flux:button>
    </div>

    @if($tab === 'overview')
        @if($this->notices !== [])
            <flux:card class="space-y-1" data-testid="bill-notices">
                @foreach($this->notices as $notice)
                    <div class="flex flex-wrap items-center gap-2 text-sm" wire:key="notice-{{ $notice->kind }}-{{ $notice->planId }}-{{ $notice->date->format('Y-m-d') }}" data-testid="bill-notice" data-kind="{{ $notice->kind }}" data-urgent="{{ $notice->urgent ? 'true' : 'false' }}">
                        @if($notice->urgent)<flux:badge size="sm" color="red" data-testid="notice-urgent">{{ __('Urgent') }}</flux:badge>@endif
                        @if($notice->material)<flux:badge size="sm" color="amber" data-testid="notice-material">{{ __('Material') }}</flux:badge>@endif
                        <span class="font-medium">{{ $notice->name }}</span>
                        <span>
                            @if($notice->kind === 'trial'){{ __('Trial ends :date', ['date' => $notice->date->format('Y-m-d')]) }}
                            @elseif($notice->kind === 'renewal'){{ __('Renews :date', ['date' => $notice->date->format('Y-m-d')]) }}
                            @else{{ __('Price changed :date: :from to :to', ['date' => $notice->date->format('Y-m-d'), 'from' => MoneyCast::format($notice->previousAmount), 'to' => MoneyCast::format($notice->newAmount)]) }}
                            @endif
                        </span>
                    </div>
                @endforeach
            </flux:card>
        @endif

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="bills-kpis">
            <flux:card><flux:text size="sm">{{ __('Left this month') }}</flux:text><div class="text-xl font-semibold" data-testid="kpi-remaining">{{ MoneyCast::format($kpis->remainingThisMonth) }}</div></flux:card>
            <flux:card><flux:text size="sm">{{ __('Paid this month') }}</flux:text><div class="text-xl font-semibold" data-testid="kpi-paid">{{ MoneyCast::format($kpis->paidThisMonth) }}</div></flux:card>
            <flux:card><flux:text size="sm">{{ __('Due in 7 days') }}</flux:text><div class="text-xl font-semibold" data-testid="kpi-week">{{ MoneyCast::format($kpis->dueNextSevenDays) }}</div></flux:card>
            <flux:card><flux:text size="sm">{{ __('Past due') }}</flux:text><div class="text-xl font-semibold" data-testid="kpi-past-due">{{ MoneyCast::format($kpis->pastDueTotal) }}</div></flux:card>
            <flux:card><flux:text size="sm">{{ __('Owed / need action') }}</flux:text><div class="text-xl font-semibold" data-testid="kpi-counts">{{ $kpis->countOwed }} / {{ $kpis->countNeedingAction }}</div></flux:card>
        </div>

        @if($empty)
            <flux:card class="text-center" data-testid="bills-empty">
                <flux:heading>{{ __('No bills yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add a bill, or let us find the ones hiding in your transactions.') }}</flux:text>
                <flux:button class="mt-3" wire:click="findMyBills" data-testid="find-my-bills">{{ __('Find my bills') }}</flux:button>
            </flux:card>
        @endif

        @foreach($sections as $key => [$title, $rows])
            @if($rows !== [])
                <div data-testid="section-{{ $key }}">
                    <flux:heading size="lg">{{ $title }}</flux:heading>
                    @foreach($rows as $row)
                        @include('livewire.partials.bill-row', ['row' => $row, 'stateColor' => $stateColor])
                    @endforeach
                </div>
            @endif
        @endforeach

        @if($overview->nextUp !== [])
            <div data-testid="section-next-up">
                <flux:heading size="lg">{{ __('Next up') }}</flux:heading>
                @foreach($overview->nextUp as $row)
                    <flux:text size="sm" wire:key="next-{{ $row->occurrenceId }}">{{ $row->dueOn->format('Y-m-d') }} · {{ $row->name }} · {{ MoneyCast::format($row->remaining) }}</flux:text>
                @endforeach
            </div>
        @endif
    @elseif($tab === 'calendar')
        @php($calendar = $this->calendar)
        <div class="space-y-3" data-testid="bills-calendar">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" data-testid="calendar-month">{{ $calendar->label }}</flux:heading>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="previousMonth" :disabled="! $calendar->canGoBack" data-testid="calendar-prev" aria-label="{{ __('Previous month') }}">←</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="goToToday" :disabled="$calendar->isCurrent" data-testid="calendar-today">{{ __('Today') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="nextMonth" :disabled="! $calendar->canGoForward" data-testid="calendar-next" aria-label="{{ __('Next month') }}">→</flux:button>
                </div>
            </div>
            <flux:text size="sm" data-testid="calendar-totals">{{ trans_choice(':count bill|:count bills', $calendar->count, ['count' => $calendar->count]) }} · {{ __('expected :expected', ['expected' => MoneyCast::format($calendar->expected)]) }} · {{ __('paid :paid', ['paid' => MoneyCast::format($calendar->paid)]) }}</flux:text>
            <div class="grid grid-cols-7 gap-1 text-xs" data-testid="calendar-grid">
                @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label)
                    <div class="font-medium text-zinc-500">{{ $label }}</div>
                @endforeach
                @foreach($calendar->days as $day)
                    <div class="min-h-16 rounded border border-neutral-200 p-1 {{ $day['inMonth'] ? '' : 'opacity-40' }}" wire:key="cal-{{ $day['date']->format('Y-m-d') }}" data-testid="calendar-day-{{ $day['date']->format('Y-m-d') }}">
                        <div class="text-zinc-500">{{ $day['date']->day }}</div>
                        @foreach($day['bills'] as $bill)
                            <button type="button" class="mt-1 block w-full truncate rounded bg-neutral-100 px-1 text-left hover:underline" wire:click="$dispatch('open-bill-drawer', { planId: {{ $bill->planId }} })" wire:key="chip-{{ $bill->occurrenceId }}" data-testid="calendar-chip" title="{{ $bill->name }} · {{ MoneyCast::format($bill->expected) }}">{{ $bill->name }}</button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="space-y-3" data-testid="all-bills">
            <div class="flex flex-wrap items-end gap-2">
                <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search bills')" class="w-56" data-testid="bills-search" />
                <flux:select wire:model.live="payment" class="w-44" data-testid="bills-payment-filter">
                    <flux:select.option value="">{{ __('Any payment state') }}</flux:select.option>
                    @foreach([OccurrenceState::Overdue, OccurrenceState::Due, OccurrenceState::PartiallyPaid, OccurrenceState::Paid] as $state)
                        <flux:select.option value="{{ $state->value }}">{{ $state->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="lifecycle" class="w-36" data-testid="bills-lifecycle-filter">
                    <flux:select.option value="">{{ __('Any lifecycle') }}</flux:select.option>
                    <flux:select.option value="paused">{{ __('Paused') }}</flux:select.option>
                    <flux:select.option value="ended">{{ __('Ended') }}</flux:select.option>
                </flux:select>
                <flux:select wire:model.live="type" class="w-40" data-testid="bills-type-filter">
                    <flux:select.option value="">{{ __('Any type') }}</flux:select.option>
                    @foreach([PlannedTransactionType::Bill, PlannedTransactionType::Subscription, PlannedTransactionType::Installment, PlannedTransactionType::Other] as $option)
                        <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button size="sm" variant="ghost" wire:click="sortBy('name')" data-testid="sort-name">{{ __('Name') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="sortBy('amount')" data-testid="sort-amount">{{ __('Amount') }}</flux:button>
            </div>

            @forelse($this->allBills as $row)
                @include('livewire.partials.bill-row', ['row' => $row, 'stateColor' => $stateColor])
            @empty
                <flux:text class="text-zinc-500" data-testid="all-bills-empty">{{ __('No bills match.') }}</flux:text>
            @endforelse
        </div>
    @endif

    <livewire:bill-drawer :plan-id="$openPlanId" />
    <livewire:plan-declaration-form />
    <livewire:occurrence-payment-panel />
</div>
