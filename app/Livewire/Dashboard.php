<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\BudgetLineSummaryDto;
use App\DTOs\DashboardLayout;
use App\Enums\ChartHeight;
use App\Enums\DashboardWidget;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionPeriod;
use App\Enums\WidgetWidth;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Insight;
use App\Models\PlannedTransaction;
use App\Models\RedbarkFeed;
use App\Models\User;
use App\Services\Budgets\BudgetPlanCalculator;
use App\Services\FirstImportSummary;
use App\Services\Recurring\OccurrenceSchedule;
use App\Services\Transfers\TransferReviewQueue;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Transactions\CategoryAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class Dashboard extends Component
{
    public bool $customising = false;

    public bool $showCustomRange = false;

    public string $customFrom = '';

    public string $customTo = '';

    public function mount(): void
    {
        $preferences = auth()->user()->preferences;

        $this->showCustomRange = $preferences->dashboardPeriod === TransactionPeriod::Custom;
        $this->customFrom = $preferences->dashboardFrom ?? '';
        $this->customTo = $preferences->dashboardTo ?? '';
    }

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

    /**
     * Re-render when a transaction or plan is saved elsewhere on the page (e.g. the global
     * transaction modal converting a transaction to a plan) so the pay-cycle figures —
     * Needed, buffer, Available, Owed, planned — stay current without a page reload.
     */
    #[On('transaction-saved')]
    #[On('plan-declared')]
    public function refreshFigures(): void
    {
        unset($this->buffer, $this->daysUntilPay, $this->totalOwed, $this->totalAvailable, $this->totalNeeded, $this->numbers, $this->budgetsThisCycle, $this->nextThreePlanned, $this->spendLast7Days, $this->needsBankConnection, $this->statementsDue); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function needsBankConnection(): bool
    {
        return ! RedbarkFeed::query()->where('user_id', auth()->id())->exists();
    }

    /**
     * Redbark-linked accounts still waiting on last month's statement check. One query; it is
     * empty without a feed because linked accounts only exist under one, and the connect
     * card covers that case.
     *
     * @return Collection<int, Account>
     */
    #[Computed]
    public function statementsDue(): Collection
    {
        return Account::query()
            ->where('user_id', auth()->id())
            ->tracked()
            ->statementDueForLastMonth()
            ->orderBy('name')
            ->get()
            ->toBase();
    }

    /** Suggested transfer pairs plus unmatched Transfer rows still waiting for a decision. */
    #[Computed]
    public function pendingTransfers(): int
    {
        return app(TransferReviewQueue::class)->pendingCount(auth()->user());
    }

    /** @return array{rules: int, categorised: int, needs_attention: int}|null */
    #[Computed]
    public function firstImportSummary(): ?array
    {
        return app(FirstImportSummary::class)->for(auth()->user());
    }

    /**
     * The top three insights. Cash-flow warnings stay off the card while the buffer hero
     * shows a figure, because the hero already says the same thing.
     *
     * @return EloquentCollection<int, Insight>
     */
    #[Computed]
    public function insights(): EloquentCollection
    {
        if (config('budget.insights_enabled') !== true) {
            return new EloquentCollection;
        }

        return Insight::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', [InsightStatus::Active->value, InsightStatus::Read->value])
            ->when($this->buffer() !== null, fn ($query) => $query->where('type', '!=', InsightType::CashFlowWarning->value))
            ->feedOrder()
            ->limit(3)
            ->get();
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
        $planRows = $this->currentPlanRows($user);

        if ($planRows !== null) {
            return $planRows;
        }

        $bounds = $user->currentPayCycleBounds();

        return $user->budgets()
            ->whereNull('budget_plan_id')
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(static function (Budget $budget) use ($bounds): array {
                if ($budget->category_id === null) {
                    return [
                        'budget' => $budget,
                        'spent' => 0,
                        'limit' => (int) $budget->limit_amount,
                    ];
                }

                $query = CategoryAttribution::query($budget->user_id, forMoney: true, forReports: true)
                    ->where('direction', TransactionDirection::Debit->value)
                    ->where('category_id', $budget->category_id);

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
     * @return Collection<int, array{planned: PlannedTransaction, next: CarbonImmutable, amount: int}>
     */
    #[Computed]
    public function nextThreePlanned(): Collection
    {
        $user = auth()->user();
        $today = $user->today();
        $horizon = $today->addMonths(3);

        $plans = $user
            ->plannedTransactions()
            ->upcoming($today)
            ->with(['account', 'category'])
            ->limit(20)
            ->get();
        $nextByPlan = (new OccurrenceSchedule)->nextFor($plans->collect(), $today);

        return $plans
            ->map(static fn (PlannedTransaction $plan): ?array => ($next = $nextByPlan[$plan->id]) === null || $next->date->greaterThan($horizon)
                ? null
                : ['planned' => $plan, 'next' => $next->date, 'amount' => $next->amount])
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
        $today = $user->today();
        $windowStart = $today->subDays(13);

        $debits = $user->transactions()
            ->current()
            ->countable()
            ->whereHas('account', static fn ($account) => $account->where('exclude_from_reports', false))
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

    /** Turns customise mode on or off. */
    public function toggleCustomise(): void
    {
        $this->customising = ! $this->customising;
    }

    public function hideWidget(string $key): void
    {
        $widget = DashboardWidget::tryFrom($key);

        if ($widget !== null) {
            $this->saveLayout($this->layout()->hide($widget));
        }
    }

    public function showWidget(string $key): void
    {
        $widget = DashboardWidget::tryFrom($key);

        if ($widget !== null) {
            $this->saveLayout($this->layout()->show($widget));
        }
    }

    public function toggleCollapsed(string $key): void
    {
        $widget = DashboardWidget::tryFrom($key);

        if ($widget !== null) {
            $this->saveLayout($this->layout()->toggleCollapsed($widget));
        }
    }

    /**
     * Moves a widget to a zero-based position among the widgets drawn on the page, which leave out one
     * that is unavailable; called by drag reordering.
     */
    public function reorder(string $item, int $position): void
    {
        $widget = DashboardWidget::tryFrom($item);

        if ($widget === null) {
            return;
        }

        $shown = array_values(array_filter($this->visibleWidgets, static fn (DashboardWidget $other): bool => $other !== $widget)); // @phpstan-ignore property.notFound
        $all = array_values(array_filter($this->layout()->visible(), static fn (DashboardWidget $other): bool => $other !== $widget));
        $anchor = $shown[$position] ?? null;

        $this->saveLayout($this->layout()->move($widget, $anchor === null ? count($all) : (int) array_search($anchor, $all, true)));
    }

    public function setWidth(string $key, string $width): void
    {
        $widget = DashboardWidget::tryFrom($key);
        $choice = WidgetWidth::tryFrom($width);

        if ($widget !== null && $choice !== null) {
            $this->saveLayout($this->layout()->withWidth($widget, $choice));
        }
    }

    public function setChartHeight(string $key, string $height): void
    {
        $widget = DashboardWidget::tryFrom($key);
        $choice = ChartHeight::tryFrom($height);

        if ($widget !== null && $widget->isChart() && $choice !== null) {
            $this->saveLayout($this->layout()->withHeight($widget, $choice));
        }
    }

    public function setTwoColumn(bool $twoColumn): void
    {
        $this->saveLayout($this->layout()->withTwoColumn($twoColumn));
    }

    public function resetLayout(): void
    {
        $this->saveLayout(new DashboardLayout);
    }

    /**
     * Saves the period the dashboard widgets follow. Custom is saved with setCustomRange().
     */
    public function setPeriod(string $key): void
    {
        $period = TransactionPeriod::tryFrom($key);

        if ($period === null || ! in_array($period, TransactionPeriod::dashboardOptions(), true)) {
            return;
        }

        if ($period === TransactionPeriod::Custom) {
            $this->showCustomRange = true;

            return;
        }

        $this->showCustomRange = false;
        $this->savePeriod($period, null, null);
    }

    /**
     * Saves a custom start and end. A pair that is invalid or backwards is saved as the last
     * 30 days instead.
     */
    public function setCustomRange(): void
    {
        if (! TransactionPeriod::validRange($this->customFrom, $this->customTo)) {
            $this->showCustomRange = false;
            $this->savePeriod(TransactionPeriod::ThirtyDays, null, null);

            return;
        }

        $this->savePeriod(TransactionPeriod::Custom, $this->customFrom, $this->customTo);
    }

    #[Computed]
    public function layout(): DashboardLayout
    {
        return auth()->user()->preferences->dashboardLayout;
    }

    #[Computed]
    public function period(): DashboardPeriod
    {
        return DashboardPeriod::for(auth()->user());
    }

    /** @return list<DashboardWidget> */
    #[Computed]
    public function visibleWidgets(): array
    {
        $user = auth()->user();

        return array_values(array_filter($this->layout()->visible(), static fn (DashboardWidget $widget): bool => $widget->availableTo($user)));
    }

    /**
     * The hidden widgets the user can add back: only those that have something to show.
     *
     * @return list<DashboardWidget>
     */
    #[Computed]
    public function hiddenOffers(): array
    {
        $user = auth()->user();

        return array_values(array_filter(
            $this->layout()->hiddenWidgets(),
            static fn (DashboardWidget $widget): bool => $widget->hasData($user),
        ));
    }

    public function render(): View
    {
        return view('livewire.dashboard');
    }

    private function saveLayout(DashboardLayout $layout): void
    {
        auth()->user()->updatePreferences(['dashboardLayout' => $layout->toArray()]);

        unset($this->layout, $this->visibleWidgets, $this->hiddenOffers); // @phpstan-ignore property.notFound
    }

    private function savePeriod(TransactionPeriod $period, ?string $from, ?string $to): void
    {
        auth()->user()->updatePreferences([
            'dashboardPeriod' => $period->value,
            'dashboardFrom' => $from,
            'dashboardTo' => $to,
        ]);

        unset($this->period); // @phpstan-ignore property.notFound

        $this->dispatch('dashboard-period-changed');
    }

    /**
     * The current month's initialised budget plan lines that carry a budget or spend, with the
     * figures the budget page shows for them, or null when the month has no initialised plan.
     *
     * @return Collection<int, array{budget: Budget, spent: int, limit: int}>|null
     */
    private function currentPlanRows(User $user): ?Collection
    {
        $plan = BudgetPlan::query()
            ->where('user_id', $user->id)
            ->whereNotNull('initialised_at')
            ->whereDate('period_start', $user->today()->startOfMonth())
            ->first();

        if ($plan === null) {
            return null;
        }

        $summary = app(BudgetPlanCalculator::class)->summarise($plan);
        $lines = $plan->lines()->where('user_id', $user->id)->get()->keyBy('id');

        return collect($summary->lines)
            ->reject(static fn (BudgetLineSummaryDto $line): bool => $line->isShared() || ($line->allocation + $line->rolledOver === 0 && $line->actual === 0))
            ->map(static function (BudgetLineSummaryDto $line) use ($lines): ?array {
                $budget = $lines->get($line->budgetId);

                if ($budget === null) {
                    return null;
                }

                $budget->name = $line->name;

                return [
                    'budget' => $budget,
                    'spent' => $line->actual,
                    'limit' => $line->allocation + $line->rolledOver,
                ];
            })
            ->filter()
            ->values();
    }
}
