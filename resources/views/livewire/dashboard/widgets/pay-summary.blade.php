@use('App\Casts\MoneyCast')

<div class="space-y-4">
    @php
        $buf = $dashboard->buffer;
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
            <p class="mt-2 text-sm">After covering {{ MoneyCast::format($dashboard->totalNeeded) }} of planned spend until payday.</p>
        @else
            <h2 class="font-display text-2xl font-black tracking-tight">YOU ARE SHORT BY</h2>
            <div class="buffer-amt mt-2 text-5xl font-black">{{ MoneyCast::format(abs($buf)) }}</div>
            <p class="mt-2 text-sm">Planned spend exceeds what you have available by payday.</p>
        @endif
    </section>

    <div class="grid gap-3 sm:grid-cols-3">
        <x-cib.money-card label="Owed" :amount="$dashboard->numbers['owed']" tone="owed"/>
        <x-cib.money-card label="Available" :amount="$dashboard->numbers['available']" tone="available"/>
        <x-cib.money-card label="Needed" :amount="$dashboard->numbers['needed']" tone="needed"/>
    </div>
    @if (config('budget.multi_currency'))
        <p class="text-sm text-gray-500" data-test="dashboard-base-currency">{{ __('Owed, Available, Needed and the buffer are in :currency', ['currency' => auth()->user()->base_currency]) }}</p>
    @endif
</div>
