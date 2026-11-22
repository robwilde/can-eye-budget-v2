@php
    $summary = $this->summary;
    $expenseRows = $this->expenseRows;
    $incomeRows = $this->incomeRows;
    $change = static fn (?int $value): string => $value === null ? '' : ($value > 0 ? '+' : '').$value.'% vs previous period';
    $rangeLabel = $window === null ? $periodLabel : $window['start']->format('j M Y').' to '.$window['end']->format('j M Y');
@endphp

<div class="mx-auto max-w-4xl space-y-6 p-6" data-testid="report-print">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Report</flux:heading>
            <flux:text class="mt-1">{{ $rangeLabel }} · {{ $mode === 'plan' ? 'Plan' : 'Real' }}</flux:text>
            @if($topOnly)
                <flux:text class="mt-1" data-testid="print-top-only">Showing the top {{ $topLimit }} categories of each section; totals cover every category.</flux:text>
            @endif
            @if($accountName !== null || $tagName !== null || $filters->minCents !== null || $filters->maxCents !== null)
                <flux:text class="mt-1" data-testid="print-filters">
                    Filters:
                    {{ collect([
                        $accountName !== null ? 'Account '.$accountName : null,
                        $tagName !== null ? 'Tag '.$tagName : null,
                        $filters->minCents !== null ? 'Min '.$formatMoney($filters->minCents) : null,
                        $filters->maxCents !== null ? 'Max '.$formatMoney($filters->maxCents) : null,
                    ])->filter()->implode(' · ') }}
                </flux:text>
            @endif
        </div>
        <button type="button" onclick="window.print()" class="print:hidden report-chip active" data-testid="print-button">Print</button>
    </div>

    <table class="w-full text-sm" data-testid="print-summary">
        <thead>
            <tr class="border-b-2 border-black text-left">
                <th class="py-2">Summary</th>
                <th class="py-2 text-right">Amount</th>
                <th class="py-2 text-right">Change</th>
            </tr>
        </thead>
        <tbody>
            <tr class="border-b"><td class="py-2">Income</td><td class="py-2 text-right" data-testid="print-income">{{ $formatMoney($summary['in']) }}</td><td class="py-2 text-right">{{ $change($summary['inChange']) }}</td></tr>
            <tr class="border-b"><td class="py-2">Expenses</td><td class="py-2 text-right" data-testid="print-expenses">{{ $formatMoney($summary['out']) }}</td><td class="py-2 text-right">{{ $change($summary['outChange']) }}</td></tr>
            <tr class="border-b font-semibold"><td class="py-2">Net</td><td class="py-2 text-right" data-testid="print-net">{{ $formatMoney($summary['net']) }}</td><td class="py-2 text-right">{{ $change($summary['netChange']) }}</td></tr>
        </tbody>
    </table>

    @foreach([['Income', $incomeRows, $summary['in']], ['Expenses', $expenseRows, $summary['out']]] as [$heading, $rows, $total])
        <table class="w-full text-sm" data-testid="print-{{ strtolower($heading) }}-rows">
            <thead>
                <tr class="border-b-2 border-black text-left">
                    <th class="py-2">{{ $heading }}</th>
                    <th class="py-2 text-right">Count</th>
                    <th class="py-2 text-right">Share</th>
                    <th class="py-2 text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $bucket)
                    <tr class="border-b">
                        <td class="py-2">{{ $bucket['name'] }}</td>
                        <td class="py-2 text-right">{{ $bucket['count'] }}</td>
                        <td class="py-2 text-right">{{ $bucket['pct'] }}%</td>
                        <td class="py-2 text-right">{{ $formatMoney($bucket['total']) }}</td>
                    </tr>
                    @foreach($showSubcategories ? $bucket['children'] : [] as $child)
                        <tr class="border-b text-fg-3">
                            <td class="py-1 ps-4">{{ $child['path'] }}</td>
                            <td class="py-1 text-right">{{ $child['count'] }}</td>
                            <td class="py-1 text-right">{{ $child['pct'] }}%</td>
                            <td class="py-1 text-right">{{ $formatMoney($child['total']) }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="4" class="py-2 text-fg-3">None in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="font-semibold"><td class="py-2" colspan="3">Total {{ strtolower($heading) }}</td><td class="py-2 text-right">{{ $formatMoney($total) }}</td></tr>
            </tfoot>
        </table>
    @endforeach
</div>
