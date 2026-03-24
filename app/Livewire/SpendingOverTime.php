<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Carbon\Constants\UnitValue;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use stdClass;

final class SpendingOverTime extends Component
{
    public string $period = '30d';

    /** @return array<int, array{date: string, total: int, accounts: array<int, array{name: string, total: int}>}> */
    #[Computed(persist: true)]
    public function timeSeriesData(): array
    {
        $start = $this->periodStart();
        $aggregation = $this->aggregationLevel();

        /** @var Connection $connection */
        $connection = Transaction::query()->getConnection();
        $isSqlite = $connection->getDriverName() === 'sqlite';

        $groupExpression = match ($aggregation) {
            'day' => 'DATE(transactions.post_date)',
            'week' => $isSqlite
                ? "DATE(transactions.post_date, '-' || ((CAST(strftime('%w', transactions.post_date) AS INTEGER) + 6) % 7) || ' days')"
                : 'DATE(DATE_SUB(transactions.post_date, INTERVAL WEEKDAY(transactions.post_date) DAY))',
            'month' => $isSqlite
                ? "strftime('%Y-%m-01', transactions.post_date)"
                : "DATE_FORMAT(transactions.post_date, '%Y-%m-01')",
        };

        $netExpression = 'SUM(transactions.amount)';

        /** @var Collection<int, stdClass> $rows */
        $rows = Transaction::query()
            ->join('accounts', 'transactions.account_id', '=', 'accounts.id')
            ->where('transactions.user_id', auth()->id())
            ->whereColumn('accounts.user_id', 'transactions.user_id')
            ->where('transactions.post_date', '>=', $start)
            ->selectRaw("{$groupExpression} as period_date, transactions.account_id, accounts.name as account_name, {$netExpression} as total")
            ->groupBy('period_date', 'transactions.account_id', 'accounts.name')
            ->orderBy('period_date')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var Collection<string, Collection<int, stdClass>> $grouped */
        $grouped = $rows->groupBy('period_date');

        return $this->fillPeriods($aggregation, $start, $grouped);
    }

    public function updatedPeriod(): void
    {
        unset($this->timeSeriesData); // @phpstan-ignore property.notFound
        $this->dispatch('spending-over-time-updated', data: $this->timeSeriesData, aggregation: $this->aggregationLevel()); // @phpstan-ignore property.notFound
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <div class="space-y-4">
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-8 w-32"></div>
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-64"></div>
            </div>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.spending-over-time', [
            'formatMoney' => MoneyCast::format(...),
            'aggregation' => $this->aggregationLevel(),
        ]);
    }

    /**
     * @param  'day'|'week'|'month'  $aggregation
     * @param  Collection<string, Collection<int, stdClass>>  $grouped
     * @return array<int, array{date: string, total: int, accounts: array<int, array{name: string, total: int}>}>
     */
    private function fillPeriods(string $aggregation, CarbonImmutable $start, Collection $grouped): array
    {
        $interval = match ($aggregation) {
            'day' => '1 day',
            'week' => '1 week',
            default => '1 month',
        };

        $periodDates = CarbonPeriod::create($start->startOfDay(), $interval, now()->startOfDay());

        $series = [];

        foreach ($periodDates as $date) {
            $key = match ($aggregation) {
                'day' => $date->format('Y-m-d'),
                'week' => $date->startOfWeek(UnitValue::MONDAY)->format('Y-m-d'),
                default => $date->format('Y-m-01'),
            };

            if (isset($series[$key])) {
                continue;
            }

            /** @var Collection<int, stdClass> $periodRows */
            $periodRows = $grouped->get($key, collect());

            $accounts = [];
            foreach ($periodRows as $row) {
                $accounts[] = [
                    'name' => (string) $row->account_name,
                    'total' => (int) $row->total,
                ];
            }

            $series[$key] = [
                'date' => $key,
                'total' => (int) $periodRows->sum('total'),
                'accounts' => $accounts,
            ];
        }

        return array_values($series);
    }

    private function periodStart(): CarbonImmutable
    {
        return match ($this->period) {
            '7d' => CarbonImmutable::now()->subDays(7)->startOfDay(),
            '30d' => CarbonImmutable::now()->subDays(30)->startOfDay(),
            '90d' => CarbonImmutable::now()->subDays(90)->startOfWeek(UnitValue::MONDAY),
            '12m' => CarbonImmutable::now()->subMonths(12)->startOfMonth(),
            default => CarbonImmutable::now()->subDays(30)->startOfDay(),
        };
    }

    /** @return 'day'|'week'|'month' */
    private function aggregationLevel(): string
    {
        return match ($this->period) {
            '7d', '30d' => 'day',
            '90d' => 'week',
            '12m' => 'month',
            default => 'day',
        };
    }
}
