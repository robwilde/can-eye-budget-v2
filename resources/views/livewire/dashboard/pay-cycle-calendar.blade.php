@use('App\Casts\MoneyCast')
@use('App\Livewire\Dashboard\PayCycleCalendar')

<section class="pay-cycle-cal">
    @if ($this->bounds === null)
        <x-cib.empty-state
                icon="calendar-days"
                title="Set up your pay cycle"
                description="Configure your pay frequency and next payday to see your fortnight at a glance."
        >
            <x-slot:action>
                <a class="link cyc-empty-link" href="{{ route('pay-cycle.edit') }}">
                    Configure pay cycle →
                </a>
            </x-slot:action>
        </x-cib.empty-state>
    @else
        <header class="cyc-head">
            <div>
                <h3 class="cyc-title">Pay cycle</h3>
                <p class="cyc-sub">
                    {{ $this->header['rangeLabel'] }}
                    @if ($this->header['daysUntilPay'] !== null)
                        · {{ $this->header['daysUntilPay'] }}d to payday
                    @endif
                </p>
            </div>
            <div class="cyc-nav">
                <button type="button" wire:click="previousCycle" title="Previous cycle" aria-label="Previous cycle">
                    <flux:icon.chevron-left variant="micro"/>
                </button>
                <button type="button" wire:click="goToCurrentCycle" @disabled($this->isCurrentCycle)>
                    Today
                </button>
                <button type="button" wire:click="nextCycle" title="Next cycle" aria-label="Next cycle">
                    <flux:icon.chevron-right variant="micro"/>
                </button>
            </div>
        </header>

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
                            wire:click="selectDay('{{ $day->iso }}')"
                            wire:key="cyc-day-{{ $day->iso }}"
                            @class([
                                'cyc-day',
                                'past' => $day->isPast && ! $day->isToday,
                                'today' => $day->isToday,
                                'payday' => $day->isCycleEnd && $this->isCurrentCycle,
                                'lastpay' => $day->isCycleStart,
                                'active' => $day->iso === $this->selectedDate,
                            ])
                    >
                        <div class="cyc-day-top">
                            <span class="cyc-dnum">{{ $day->day }}</span>
                            @if ($day->isToday)
                                <span class="cyc-today-pill">TODAY</span>
                            @endif
                            @if ($day->isCycleEnd && $this->isCurrentCycle)
                                <span class="cyc-paytag">PAYDAY</span>
                            @elseif ($day->isCycleStart)
                                <span class="cyc-paytag dim">PAID</span>
                            @endif
                        </div>
                        <div class="cyc-day-body">
                            @foreach (array_slice($day->pips, 0, PayCycleCalendar::MAX_PIPS_PER_DAY) as $pip)
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
                        @if ($this->selectedDay['isCycleEnd'] && $this->isCurrentCycle)
                            <span class="chip-pay">Payday eve</span>
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
    @endif
</section>
