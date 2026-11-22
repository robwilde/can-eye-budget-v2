@use('App\Casts\MoneyCast')

@php($netWorth = $this->report)

<div class="space-y-4" data-testid="report-net-worth">
    <div class="grid gap-3 sm:grid-cols-3">
        <x-cib.money-card label="Net worth now" :tone="$netWorth['current'] >= 0 ? 'available' : 'owed'" :amount="$netWorth['current']" hint="Assets {{ MoneyCast::format($netWorth['assetTotal']) }} · Debts {{ MoneyCast::format(abs($netWorth['liabilityTotal'])) }}"/>
        <x-cib.money-card label="Start of period" :amount="$netWorth['start']" hint="{{ \Illuminate\Support\Carbon::parse($netWorth['from'])->format('j M Y') }}"/>
        <x-cib.money-card label="End of period" :amount="$netWorth['end']" hint="{{ \Illuminate\Support\Carbon::parse($netWorth['to'])->format('j M Y') }}"/>
    </div>

    @if ($netWorth['change'] !== null)
        <p class="text-sm font-bold {{ $netWorth['change'] >= 0 ? 'text-cib-green-600' : 'text-red-600' }}" data-testid="net-worth-change">
            {{ $netWorth['change'] >= 0 ? '+' : '-' }}{{ MoneyCast::format(abs($netWorth['change'])) }} over the period
        </p>
    @else
        <p class="text-sm text-gray-500" data-testid="net-worth-change-withheld">Change is not shown: balance history does not cover the start of this period.</p>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities'] as $side => $heading)
            <div data-testid="net-worth-{{ $side }}">
                <div class="flex items-center justify-between font-bold">
                    <span>{{ $heading }}</span>
                    <span>{{ MoneyCast::format($side === 'assets' ? $netWorth['assetTotal'] : $netWorth['liabilityTotal']) }}</span>
                </div>
                @php($groups = array_filter($netWorth['groups'], static fn (array $group): bool => $group['side'] === $side))
                @forelse ($groups as $group)
                    <div class="flex items-center justify-between gap-3 py-1 text-sm" data-testid="net-worth-group" wire:key="net-worth-group-{{ $group['key'] }}">
                        <span class="flex-1">{{ $group['label'] }}</span>
                        <x-cib.line-spark :points="$group['series']" :favourable="$group['favourable']"/>
                        <span class="w-28 text-right">{{ MoneyCast::format($group['total']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">None</p>
                @endforelse
            </div>
        @endforeach
    </div>
</div>
