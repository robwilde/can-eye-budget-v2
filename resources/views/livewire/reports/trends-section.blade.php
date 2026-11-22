@use('App\Casts\MoneyCast')

@php($trends = $this->report)
@php($rate = static fn (?int $value): string => $value === null ? '–' : $value.'%')

<div class="space-y-4" data-testid="report-trends">
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="trends-table">
            <thead>
                <tr class="text-left text-gray-500">
                    <th scope="col" class="py-1">Month</th>
                    <th scope="col" class="py-1 text-right">Income</th>
                    <th scope="col" class="py-1 text-right">Expenses</th>
                    <th scope="col" class="py-1 text-right">Net</th>
                    <th scope="col" class="py-1 text-right">Savings rate</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($trends['months'] as $month)
                    <tr wire:key="trends-month-{{ $month['key'] }}" data-testid="trends-month-{{ $month['key'] }}">
                        <th scope="row" class="py-1 text-left font-normal">{{ $month['label'] }}</th>
                        <td class="py-1 text-right">{{ MoneyCast::format($month['income']) }}</td>
                        <td class="py-1 text-right">{{ MoneyCast::format($month['expenses']) }}</td>
                        <td class="py-1 text-right {{ $month['net'] < 0 ? 'text-red-600' : '' }}">{{ MoneyCast::format($month['net']) }}</td>
                        <td class="py-1 text-right" data-testid="savings-rate">{{ $rate($month['savingsRate']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-bold" data-testid="trends-average">
                    <th scope="row" class="py-1 text-left">Average</th>
                    <td class="py-1 text-right">{{ MoneyCast::format($trends['averages']['income']) }}</td>
                    <td class="py-1 text-right">{{ MoneyCast::format($trends['averages']['expenses']) }}</td>
                    <td class="py-1 text-right">{{ MoneyCast::format($trends['averages']['net']) }}</td>
                    <td class="py-1 text-right" data-testid="average-savings-rate">{{ $rate($trends['averages']['savingsRate']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="grid gap-3 sm:grid-cols-2" data-testid="weekday-weekend">
        <x-cib.money-card label="Weekday spending" :amount="$trends['weekday']['average']" hint="average per transaction · {{ $trends['weekday']['count'] }} {{ \Illuminate\Support\Str::plural('transaction', $trends['weekday']['count']) }}" data-testid="weekday-average"/>
        <x-cib.money-card label="Weekend spending" :amount="$trends['weekend']['average']" hint="average per transaction · {{ $trends['weekend']['count'] }} {{ \Illuminate\Support\Str::plural('transaction', $trends['weekend']['count']) }}" data-testid="weekend-average"/>
    </div>
</div>
