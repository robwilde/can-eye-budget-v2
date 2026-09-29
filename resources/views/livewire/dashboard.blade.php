@use('App\Casts\MoneyCast')

<div class="space-y-6">
    @if ($this->needsBankConnection)
        <section data-test="dashboard-connect-bank-card" class="flex flex-wrap items-center justify-between gap-4 border-2 border-black rounded-[28px] bg-cib-yellow-400 p-6 shadow-[3px_3px_0_0_#111]">
            <div>
                <h2 class="font-display text-2xl font-black tracking-tight">Connect your bank</h2>
                <p class="mt-2 text-sm">Link your accounts through Redbark so your transactions sync automatically.</p>
            </div>
            <a href="{{ route('connect-bank') }}" wire:navigate class="inline-flex items-center gap-2 rounded-md border-2 border-cib-black bg-white px-4 py-2 text-sm font-bold text-cib-black shadow-pop-sm">
                Connect your bank
            </a>
        </section>
    @endif

    @if ($this->statementsDue->isNotEmpty())
        <x-cib.card data-test="dashboard-statements-due-card">
            <flux:heading size="lg">{{ __('Check last month\'s statements') }}</flux:heading>
            <flux:text size="sm" class="mt-1">
                {{ __('Upload each bank statement to confirm the feed delivered every transaction, then close the month.') }}
            </flux:text>
            <ul class="mt-3 space-y-1">
                @foreach ($this->statementsDue as $dueAccount)
                    <li wire:key="statement-due-{{ $dueAccount->id }}">
                        <a href="{{ route('accounts.reconcile', $dueAccount) }}" wire:navigate
                           class="text-sm font-bold underline decoration-dotted"
                           data-test="dashboard-statement-due-{{ $dueAccount->id }}">{{ $dueAccount->name }}</a>
                    </li>
                @endforeach
            </ul>
        </x-cib.card>
    @endif

    @if ($this->pendingTransfers > 0)
        <x-cib.card data-test="dashboard-pending-transfers-card">
            <flux:heading size="lg">{{ trans_choice(':count possible transfer to review|:count possible transfers to review', $this->pendingTransfers) }}</flux:heading>
            <flux:text size="sm" class="mt-1">
                {{ __('Confirm each one once; the same transfer is then linked automatically on later imports.') }}
            </flux:text>
            <a href="{{ route('transfers.review') }}" wire:navigate
               class="mt-3 inline-block text-sm font-bold underline decoration-dotted"
               data-test="dashboard-pending-transfers-link">{{ __('Review transfers') }}</a>
        </x-cib.card>
    @endif

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

            <livewire:dashboard.pay-cycle-calendar/>
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

    <livewire:dashboard.monthly-projection lazy/>
</div>
