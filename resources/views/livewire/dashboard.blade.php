@use('App\Casts\MoneyCast')
@use('App\Enums\TransactionDirection')

<div class="grid gap-4 lg:grid-cols-[1fr_300px]">
    <div class="space-y-4">
        @php
            $buf = $this->buffer;
        @endphp

        <section @class([
            'buffer-hero border-2 border-black rounded-[28px] p-6 shadow-[3px_3px_0_0_#111]',
            'bg-cib-green-400' => $buf !== null && $buf >= 0,
            'bg-red-400' => $buf !== null && $buf < 0,
            'bg-cib-cream-50' => $buf === null,
            'pos' => $buf !== null && $buf >= 0,
            'neg' => $buf !== null && $buf < 0,
        ])>
            @if ($buf === null)
                <h2 class="font-display text-2xl font-black tracking-tight">SET UP PAY CYCLE</h2>
                <p class="mt-2 text-sm">Configure your next pay date and amount to see your buffer.</p>
            @elseif ($buf >= 0)
                <h2 class="font-display text-2xl font-black tracking-tight">YOU CAN AFFORD</h2>
                <div class="buffer-amt mt-2 text-5xl font-black">{{ MoneyCast::format($buf) }}</div>
                <p class="mt-2 text-sm">After covering {{ MoneyCast::format($this->totalNeeded) }} of planned spend until payday.</p>
            @else
                <h2 class="font-display text-2xl font-black tracking-tight">YOU ARE SHORT BY</h2>
                <div class="buffer-amt mt-2 text-5xl font-black">{{ MoneyCast::format(abs($buf)) }}</div>
                <p class="mt-2 text-sm">Planned spend exceeds what you have available by payday.</p>
            @endif
        </section>

        <div class="grid gap-3 sm:grid-cols-3">
            <x-cib.money-card label="Owed" :amount="$this->numbers['owed']" tone="owed"/>
            <x-cib.money-card label="Available" :amount="$this->numbers['available']" tone="available"/>
            <x-cib.money-card label="Needed" :amount="$this->numbers['needed']" tone="needed"/>
        </div>

        <section>
            <x-cib.sec-head title="Recent activity" :href="route('transactions')"/>
            <div class="space-y-2">
                @forelse ($this->recentTransactions as $tx)
                    <x-cib.tx-row
                            :transaction-id="$tx->id"
                            :name="$tx->description ?? '—'"
                            :amount="$tx->amount"
                            :tone="$tx->direction === TransactionDirection::Debit ? 'out' : 'in'"
                            :icon="$tx->category?->resolveIcon()"
                    />
                @empty
                    <p class="text-sm text-gray-500">No recent activity.</p>
                @endforelse
            </div>
        </section>
    </div>

    <aside class="space-y-4">
        <section>
            <x-cib.sec-head title="Budgets this cycle"/>
            @forelse ($this->budgetsThisCycle as $row)
                <x-cib.budget-row
                        :name="$row['budget']->name"
                        :spent="$row['spent']"
                        :limit="$row['limit']"
                />
            @empty
                <p class="text-sm text-gray-500">No budgets yet.</p>
            @endforelse
        </section>

        <section>
            <x-cib.sec-head title="Next 3 planned"/>
            @forelse ($this->nextThreePlanned as $row)
                <div class="planned-row flex items-center justify-between py-1 text-sm">
                    <span class="font-medium">{{ $row['planned']->description }}</span>
                    <span class="text-gray-600">{{ $row['next']->format('M j') }}</span>
                    <span class="font-bold">{{ MoneyCast::format(abs((int) $row['planned']->amount)) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nothing planned.</p>
            @endforelse
        </section>

        <section>
            <x-cib.sec-head title="Spend last 7 days"/>
            <div class="spend-total text-2xl font-bold">{{ MoneyCast::format($this->spendLast7Days['sum']) }}</div>
            <x-cib.spark
                    :values="$this->spendLast7Days['sparkline']"
                    :payday-indexes="$this->spendLast7Days['paydayIndexes']"
            />
        </section>
    </aside>
</div>
