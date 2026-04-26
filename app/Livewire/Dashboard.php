<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TransactionDirection;
use App\Models\Budget;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class Dashboard extends Component
{
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <div class="space-y-4">
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 min-h-48"></div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 min-h-32"></div>
                    <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 min-h-32"></div>
                    <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 min-h-32"></div>
                </div>
            </div>
        </div>
        HTML;
    }

    #[Computed]
    public function buffer(): ?int
    {
        return auth()->user()->bufferUntilNextPay($this->totalAvailable());
    }

    #[Computed]
    public function daysUntilPay(): ?int
    {
        return auth()->user()->daysUntilNextPay();
    }

    #[Computed]
    public function totalOwed(): int
    {
        return auth()->user()->totalOwed();
    }

    #[Computed]
    public function totalAvailable(): int
    {
        return auth()->user()->totalAvailable();
    }

    #[Computed]
    public function totalNeeded(): int
    {
        return auth()->user()->totalNeededUntilPayday();
    }

    /**
     * @return array{owed: int, available: int, needed: int}
     */
    #[Computed]
    public function numbers(): array
    {
        return [
            'owed' => $this->totalOwed(),
            'available' => $this->totalAvailable(),
            'needed' => $this->totalNeeded(),
        ];
    }

    /**
     * @return Collection<int, array{budget: Budget, spent: int, limit: int}>
     */
    #[Computed]
    public function budgetsThisCycle(): Collection
    {
        $user = auth()->user();
        $bounds = $user->currentPayCycleBounds();

        return $user->budgets()
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(static function (Budget $budget) use ($bounds): array {
                $query = Transaction::query()
                    ->where('user_id', $budget->user_id)
                    ->current()
                    ->where('direction', TransactionDirection::Debit);

                if ($budget->category_id !== null) {
                    $query->where('category_id', $budget->category_id);
                }

                if ($bounds !== null) {
                    $query->whereBetween('post_date', [$bounds['start'], $bounds['end']]);
                }

                return [
                    'budget' => $budget,
                    'spent' => abs((int) $query->sum('amount')),
                    'limit' => (int) $budget->limit_amount,
                ];
            });
    }

    /**
     * @return Collection<int, array{planned: PlannedTransaction, next: CarbonImmutable}>
     */
    #[Computed]
    public function nextThreePlanned(): Collection
    {
        $today = CarbonImmutable::today();
        $horizon = $today->addMonths(3);

        return auth()->user()
            ->plannedTransactions()
            ->upcoming()
            ->with(['account', 'category'])
            ->limit(20)
            ->get()
            ->map(static fn (PlannedTransaction $plan): ?array => ($next = $plan->occurrencesBetween($today, $horizon)->first()) === null
                ? null
                : ['planned' => $plan, 'next' => $next])
            ->filter()
            ->sortBy(static fn (array $row): string => $row['next']->toDateString())
            ->take(3)
            ->values();
    }

    /**
     * @return array{sum: int, sparkline: array<int, int>, paydayIndexes: array<int, int>}
     */
    #[Computed]
    public function spendLast7Days(): array
    {
        $user = auth()->user();
        $today = CarbonImmutable::today();
        $windowStart = $today->subDays(13);

        $debits = $user->transactions()
            ->current()
            ->excludingTransfers()
            ->where('direction', TransactionDirection::Debit)
            ->whereBetween('post_date', [$windowStart->startOfDay(), $today->endOfDay()])
            ->get(['post_date', 'amount']);

        $buckets = [];
        for ($i = 0; $i < 14; $i++) {
            $buckets[$windowStart->addDays($i)->toDateString()] = 0;
        }

        foreach ($debits as $tx) {
            $key = CarbonImmutable::parse($tx->post_date)->toDateString();

            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += abs((int) $tx->amount);
            }
        }

        $values = array_values($buckets);
        $last7 = array_slice($values, -7);

        $paydayIndexes = [];
        $nextPay = $user->next_pay_date;
        if ($nextPay !== null) {
            $payKey = CarbonImmutable::parse($nextPay)->toDateString();
            $keys = array_keys($buckets);
            $index = array_search($payKey, $keys, true);
            if ($index !== false) {
                $paydayIndexes[] = $index;
            }
        }

        return [
            'sum' => array_sum($last7),
            'sparkline' => $values,
            'paydayIndexes' => $paydayIndexes,
        ];
    }

    public function render(): View
    {
        return view('livewire.dashboard');
    }
}
