@use('App\Livewire\CalendarView')

<div data-testid="calendar-view" class="pay-cycle-cal">
    <header class="cyc-head">
        <div>
            <h1 class="cyc-title">{{ $this->headerLabel['label'] }}</h1>
            <p class="cyc-sub">All transactions · history & planned · Mon–Sun</p>
        </div>
        <div class="cyc-nav">
            <button type="button" wire:click="previousMonth" title="Previous month" aria-label="Previous month">
                <flux:icon.chevron-left variant="micro"/>
            </button>
            <button type="button" wire:click="goToToday" @disabled($this->headerLabel['isCurrentMonth'])>
                Today
            </button>
            <button type="button" wire:click="nextMonth" title="Next month" aria-label="Next month">
                <flux:icon.chevron-right variant="micro"/>
            </button>
        </div>
    </header>

    <div class="quickline">
        <span class="pill pill-income">
            <span class="pill-label">Income</span>
            <span class="pill-value tabular-nums">{{ $formatMoney($this->monthTotals['income']) }}</span>
        </span>
        <span class="pill pill-posted">
            <span class="pill-label">Spend</span>
            <span class="pill-value tabular-nums">{{ $formatMoney($this->monthTotals['spend']) }}</span>
        </span>
        <span @class([
            'pill',
            'pill-buffer-pos' => $this->monthTotals['net'] >= 0,
            'pill-buffer-neg' => $this->monthTotals['net'] < 0,
        ])>
            <span class="pill-label">Net</span>
            <span class="pill-value tabular-nums">{{ $formatMoney($this->monthTotals['net']) }}</span>
        </span>
    </div>

    <div class="cyc-grid" role="grid">
        <div class="cyc-dows" role="row">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label)
                <div class="cyc-dow" role="columnheader">{{ $label }}</div>
            @endforeach
        </div>
        <div class="cyc-week-stream">
            @foreach ($this->days as $day)
                <button
                        type="button"
                        wire:click="selectDate('{{ $day->iso }}')"
                        wire:key="cal-day-{{ $day->iso }}"
                        style="grid-column-start: {{ $day->isoWeekday }}"
                        @class([
                            'cyc-day',
                            'out' => ! $day->isCurrentMonth,
                            'past' => $day->isPast && ! $day->isToday && $day->isCurrentMonth,
                            'cycle-band' => $day->isInActiveCycle && $day->isCurrentMonth,
                            'today' => $day->isToday,
                            'lastpay' => $day->isPastPayday,
                            'payday' => $day->isNextPayday,
                            'active' => $day->iso === $this->selectedDate,
                        ])
                >
                    <div class="cyc-day-top">
                        <span class="cyc-dnum">{{ $day->day }}</span>
                        @if ($day->isToday)
                            <span class="cyc-today-pill">TODAY</span>
                        @endif
                        @if ($day->isNextPayday)
                            <span class="cyc-paytag">PAYDAY</span>
                        @elseif ($day->isPastPayday)
                            <span class="cyc-paytag dim">PAID</span>
                        @endif
                    </div>
                    <div class="cyc-day-body">
                        @foreach (array_slice($day->pips, 0, CalendarView::MAX_PIPS_PER_DAY) as $pip)
                            <div @class(['cyc-pip', $pip->kind])>
                                <span class="cyc-pip-dot"></span>
                                <span class="cyc-pip-name">{{ $pip->name }}</span>
                            </div>
                        @endforeach
                        @if ($day->hiddenCount > 0)
                            <div class="cyc-pip-more">+{{ $day->hiddenCount }} more</div>
                        @endif
                    </div>
                    <x-cib.day-net :cents="$day->netCents"/>
                </button>
            @endforeach
        </div>
    </div>

    @if ($this->selectedDay !== null)
        <div class="cyc-detail">
            <div class="cyc-detail-head">
                <div>
                    <div class="cyc-detail-dow">{{ $this->selectedDay['dayLabel'] }}</div>
                    <div class="cyc-detail-date">{{ $this->selectedDay['dateLabel'] }}</div>
                </div>
                <div class="cyc-detail-meta">
                    @if ($this->selectedDay['isToday'])
                        <span class="chip-today">Today</span>
                    @endif
                    @if ($this->selectedDay['isPastPayday'])
                        <span class="chip-paid">Paid</span>
                    @endif
                    @if ($this->selectedDay['isNextPayday'])
                        <span class="chip-pay">Payday</span>
                    @endif
                    <span class="chip-count">
                        {{ count($this->selectedDay['pips']) }} item{{ count($this->selectedDay['pips']) === 1 ? '' : 's' }}
                    </span>
                </div>
            </div>
            <div class="cyc-detail-body">
                @forelse ($this->selectedDay['pips'] as $pip)
                    @php
                        $rowTone = match ($pip->kind) {
                            'inc' => 'inc',
                            'plan' => 'plan',
                            default => 'out',
                        };
                    @endphp
                    <x-cib.tx-row
                            :transaction-id="$pip->transactionId"
                            :planned-transaction-id="$pip->plannedTransactionId"
                            :occurrence-date="$pip->occurrenceDate"
                            :name="$pip->name"
                            :amount="$pip->amount"
                            :tone="$rowTone"
                            :icon="$pip->icon"
                    />
                @empty
                    <p class="cyc-empty">Nothing on this day.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
