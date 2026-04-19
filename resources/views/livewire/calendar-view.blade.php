@use('Carbon\CarbonImmutable')
<div data-testid="calendar-view" class="space-y-4">
    <header class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-3">
            <flux:button wire:click="previousMonth" variant="ghost" icon="chevron-left" size="sm" />
            <div>
                <h1 class="cib-title">{{ $this->headerLabel['label'] }}</h1>
                @if ($this->headerLabel['rangeLabel'])
                    <div class="cib-subtitle">{{ $this->headerLabel['rangeLabel'] }}</div>
                @endif
            </div>
            <flux:button wire:click="nextMonth" variant="ghost" icon="chevron-right" size="sm" />
        </div>
        @unless ($this->calendarData['isCurrentMonth'])
            <flux:button wire:click="goToToday" variant="subtle" size="sm">Today</flux:button>
        @endunless
    </header>

    <div class="quickline">
        <span class="pill pill-income">
            <span class="pill-label">Income</span>
            <span class="pill-value tabular-nums">{{ $formatMoney($this->quickline['income']) }}</span>
        </span>
        <span class="pill pill-posted">
            <span class="pill-label">Posted</span>
            <span class="pill-value tabular-nums">{{ $formatMoney($this->quickline['posted']) }}</span>
        </span>
        <span class="pill pill-planned">
            <span class="pill-label">Planned</span>
            <span class="pill-value tabular-nums">{{ $formatMoney($this->quickline['planned']) }}</span>
        </span>
        <span @class([
            'pill',
            'pill-buffer-pos' => ($this->quickline['bufferAtPayday'] ?? 0) >= 0 && $this->quickline['bufferAtPayday'] !== null,
            'pill-buffer-neg' => ($this->quickline['bufferAtPayday'] ?? 0) < 0,
            'pill-buffer-empty' => $this->quickline['bufferAtPayday'] === null,
        ])>
            <span class="pill-label">Buffer</span>
            <span class="pill-value tabular-nums">
                @if ($this->quickline['bufferAtPayday'] === null)
                    set pay cycle
                @else
                    {{ $formatMoney($this->quickline['bufferAtPayday']) }}
                @endif
            </span>
        </span>
    </div>

    <div class="week-strip">
        @foreach ($this->weekStrip as $cell)
            <button
                type="button"
                wire:key="day-{{ $cell['date'] }}"
                wire:click="selectDate('{{ $cell['date'] }}')"
                aria-pressed="{{ $cell['isSelected'] ? 'true' : 'false' }}"
                @class([
                    'day-cell',
                    'is-today' => $cell['isToday'],
                    'is-payday' => $cell['isPayday'],
                    'is-selected' => $cell['isSelected'],
                ])
            >
                <div class="day-name">{{ $cell['dayName'] }}</div>
                <div class="day-num">{{ $cell['dayOfMonth'] }}</div>
                @if ($cell['isPayday'])
                    <div class="badge-payday">PAYDAY</div>
                @endif
                @if (count($cell['dots']) > 0)
                    <div class="dots">
                        @foreach ($cell['dots'] as $dot)
                            <span @class(['dot', "dot-{$dot}"])></span>
                        @endforeach
                    </div>
                @endif
            </button>
        @endforeach
    </div>

    @if (count($this->agenda) === 0)
        <div class="empty-state">
            <flux:icon.calendar class="mx-auto size-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No transactions</flux:heading>
            <flux:text class="mt-2">No transactions found from {{ CarbonImmutable::parse($this->selectedDate)->format('j M Y') }} onward.</flux:text>
        </div>
    @else
        <div class="agenda">
            @foreach ($this->agenda as $group)
                <section wire:key="agenda-{{ $group['date'] }}" class="agenda-group">
                    <header class="agenda-head">
                        <h3>{{ $group['heading'] }}</h3>
                        <span @class(['net tabular-nums', 'pos' => $group['net'] >= 0, 'neg' => $group['net'] < 0])>
                            {{ $formatMoney($group['net']) }}
                        </span>
                    </header>
                    <div class="day-card">
                        @foreach ($group['transactions'] as $txn)
                            @php
                                $isPlanned = ($txn['type'] ?? 'actual') === 'planned';
                                $tone = match (true) {
                                    $isPlanned => 'plan',
                                    ($txn['direction'] ?? null) === 'credit' => 'inc',
                                    default => 'out',
                                };
                            @endphp
                            <x-cib.tx-row
                                :transaction-id="$isPlanned ? null : $txn['id']"
                                :planned-transaction-id="$isPlanned ? $txn['planned_transaction_id'] : null"
                                :occurrence-date="$isPlanned ? $txn['occurrence_date'] : null"
                                :name="$txn['category']"
                                :amount="$txn['amount']"
                                :tone="$tone"
                                :icon="$txn['icon'] ?? null"
                            />
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
