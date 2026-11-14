<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\BudgetLineSummaryDto;
use App\DTOs\BudgetPlanSummaryDto;
use App\Exceptions\BudgetPlanRefusedException;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Budgets\BudgetPlanCalculator;
use App\Services\Budgets\BudgetPlanManager;
use App\Services\CashOnHandCalculator;
use Carbon\CarbonImmutable;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The user's monthly budget: setup, allocations, rollover, moving money and the category drilldown.
 *
 * @property-read CarbonImmutable $viewed
 * @property-read array{min: CarbonImmutable, max: CarbonImmutable} $bounds
 * @property-read string|null $previousMonth
 * @property-read string|null $nextMonth
 * @property-read BudgetPlan|null $plan
 * @property-read BudgetPlanSummaryDto|null $summary
 * @property-read BudgetPlan|null $copySource
 * @property-read array{spending: int, income: int} $suggestion
 * @property-read bool $hasOverBudget
 * @property-read list<array{line: BudgetLineSummaryDto, depth: int}> $rows
 * @property-read BudgetLineSummaryDto|null $drilldown
 * @property-read int|null $cashOnHand
 */
final class MonthlyBudget extends Component
{
    public const int MONTHS_BACK = 24;

    public const int MONTHS_AHEAD = 24;

    public const string FILTER_ALL = 'all';

    public const string FILTER_ON_TRACK = 'on_track';

    public const string FILTER_OVER = 'over';

    public const string TAB_BUDGETED = 'budgeted';

    public const string TAB_ACTUAL = 'actual';

    #[Url(as: 'month', except: '')]
    public string $month = '';

    #[Url(except: self::FILTER_ALL)]
    public string $filter = self::FILTER_ALL;

    #[Url(except: self::TAB_BUDGETED)]
    public string $tab = self::TAB_BUDGETED;

    #[Locked]
    public ?int $planId = null;

    public ?int $drilldownId = null;

    public bool $startFresh = false;

    public string $totalInput = '';

    public string $incomeInput = '';

    public bool $showMoveModal = false;

    public string $moveFromId = '';

    public string $moveToId = '';

    public string $moveAmount = '';

    public function mount(): void
    {
        $this->month = $this->clampMonth($this->authenticatedUser(), $this->month)->format('Y-m');
        $this->loadMonth();
    }

    public function updatedMonth(): void
    {
        $this->month = $this->clampMonth($this->authenticatedUser(), $this->month)->format('Y-m');
        $this->drilldownId = null;
        $this->startFresh = false;
        $this->reset('showMoveModal', 'moveFromId', 'moveToId', 'moveAmount');
        $this->resetErrorBag();
        $this->loadMonth();
    }

    public function updatedFilter(): void
    {
        $this->filter = in_array($this->filter, [self::FILTER_ALL, self::FILTER_ON_TRACK, self::FILTER_OVER], true)
            ? $this->filter
            : self::FILTER_ALL;
    }

    public function updatedTab(): void
    {
        $this->tab = $this->tab === self::TAB_ACTUAL ? self::TAB_ACTUAL : self::TAB_BUDGETED;
    }

    #[On('transaction-saved')]
    public function refreshReadState(): void
    {
        unset($this->plan, $this->summary, $this->rows, $this->hasOverBudget, $this->copySource, $this->drilldown, $this->bounds, $this->previousMonth, $this->nextMonth); // @phpstan-ignore property.notFound
    }

    /**
     * Opens the viewed month if needed and sets its total and expected income.
     */
    public function initialise(): void
    {
        $this->resetErrorBag();
        $user = $this->authenticatedUser();
        $total = $this->cents($this->totalInput);
        $income = mb_trim($this->incomeInput) === '' ? 0 : $this->cents($this->incomeInput);

        if ($total === null || $income === null) {
            $this->addError('setup', __('Enter budgeted spending and expected income as amounts of zero or more.'));

            return;
        }

        $this->attempt(function () use ($user, $total, $income): void {
            $plan = $this->openViewedMonth($user);
            app(BudgetPlanManager::class)->initialise($user, $plan, $total, $income);
            Flux::toast(text: __('Budget saved'), variant: 'success');
        });
    }

    /**
     * Copies the latest earlier initialised month into the viewed one.
     */
    public function copyFromPrevious(): void
    {
        $this->resetErrorBag();
        $user = $this->authenticatedUser();

        $this->attempt(function () use ($user): void {
            $plan = $this->openViewedMonth($user);
            app(BudgetPlanManager::class)->copyFrom($user, $plan);
            $this->loadMonth();
            Flux::toast(text: __('Budget copied'), variant: 'success');
        });
    }

    /**
     * Sets a category's allocation in dollars; blank makes a subcategory Shared.
     */
    public function setAllocation(int $lineId, string $amount): void
    {
        $this->resetErrorBag();
        $user = $this->authenticatedUser();
        $line = $this->loadLine($user, $lineId);
        $amount = mb_trim($amount);
        $cents = null;

        if ($amount !== '') {
            $cents = $this->cents($amount);

            if ($cents === null) {
                $this->addError('budget', __('Enter an allocation of zero or more.'));

                return;
            }
        } elseif (! $this->isSubcategory($user, $line)) {
            $cents = 0;
        }

        $this->attempt(fn () => app(BudgetPlanManager::class)->setAllocation($user, $line, $cents));
    }

    /**
     * Turns rollover on or off for a category from this month on.
     */
    public function setRollover(int $lineId, bool $enabled): void
    {
        $this->resetErrorBag();
        $user = $this->authenticatedUser();
        $line = $this->loadLine($user, $lineId);

        $this->attempt(fn () => app(BudgetPlanManager::class)->setRollover($user, $line, $enabled));
    }

    /**
     * Moves allocation between the two chosen categories.
     */
    public function moveMoney(): void
    {
        $this->resetErrorBag();
        $user = $this->authenticatedUser();
        $cents = $this->cents($this->moveAmount);

        if (! ctype_digit($this->moveFromId) || ! ctype_digit($this->moveToId) || $cents === null || $cents === 0) {
            $this->addError('move', __('Choose two categories and an amount above zero.'));

            return;
        }

        $from = $this->loadLine($user, (int) $this->moveFromId);
        $to = $this->loadLine($user, (int) $this->moveToId);

        $this->attempt(function () use ($user, $from, $to, $cents): void {
            app(BudgetPlanManager::class)->moveMoney($user, $from, $to, $cents);
            $this->reset('showMoveModal', 'moveFromId', 'moveToId', 'moveAmount');
            $this->resetErrorBag('move');
            Flux::toast(text: __('Money moved'), variant: 'success');
        }, 'move');
    }

    #[Computed]
    public function viewed(): CarbonImmutable
    {
        return $this->clampMonth($this->authenticatedUser(), $this->month);
    }

    /** @return array{min: CarbonImmutable, max: CarbonImmutable} */
    #[Computed]
    public function bounds(): array
    {
        $user = $this->authenticatedUser();
        $today = $user->today()->startOfMonth();
        $floor = $today->subMonthsNoOverflow(self::MONTHS_BACK);
        $oldest = Transaction::query()->where('user_id', $user->id)->min('post_date');

        if ($oldest !== null) {
            $oldestMonth = CarbonImmutable::parse((string) $oldest)->startOfMonth();
            $floor = $oldestMonth->lessThan($floor) ? $oldestMonth : $floor;
        }

        return ['min' => $floor, 'max' => $today->addMonthsNoOverflow(self::MONTHS_AHEAD)];
    }

    #[Computed]
    public function previousMonth(): ?string
    {
        $previous = $this->viewed->subMonthNoOverflow();

        return $previous->lessThan($this->bounds['min']) ? null : $previous->format('Y-m');
    }

    #[Computed]
    public function nextMonth(): ?string
    {
        $next = $this->viewed->addMonthNoOverflow();

        return $next->greaterThan($this->bounds['max']) ? null : $next->format('Y-m');
    }

    #[Computed]
    public function plan(): ?BudgetPlan
    {
        if ($this->planId === null) {
            return null;
        }

        return BudgetPlan::query()->where('user_id', $this->authenticatedUser()->id)->find($this->planId);
    }

    #[Computed]
    public function summary(): ?BudgetPlanSummaryDto
    {
        $plan = $this->plan;

        return $plan === null ? null : app(BudgetPlanCalculator::class)->summarise($plan);
    }

    /** The earlier initialised month a copy would come from. */
    #[Computed]
    public function copySource(): ?BudgetPlan
    {
        if ($this->plan?->initialised_at !== null) {
            return null;
        }

        return BudgetPlan::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->whereNotNull('initialised_at')
            ->whereDate('period_start', '<', $this->viewed)
            ->orderByDesc('period_start')
            ->first();
    }

    /** @return array{spending: int, income: int} */
    #[Computed]
    public function suggestion(): array
    {
        return app(BudgetPlanManager::class)->suggestFromHistory($this->authenticatedUser());
    }

    #[Computed]
    public function hasOverBudget(): bool
    {
        return collect($this->summary->lines ?? [])
            ->contains(static fn (BudgetLineSummaryDto $line): bool => $line->available !== null && $line->available < 0);
    }

    /**
     * The category lines in tree order, narrowed by the status filter.
     *
     * @return list<array{line: BudgetLineSummaryDto, depth: int}>
     */
    #[Computed]
    public function rows(): array
    {
        $lines = $this->summary->lines ?? [];
        $byParent = [];
        $present = [];

        foreach ($lines as $line) {
            $present[$line->categoryId] = true;
        }

        foreach ($lines as $line) {
            $parent = $line->parentCategoryId !== null && isset($present[$line->parentCategoryId]) ? $line->parentCategoryId : 0;
            $byParent[$parent][] = $line;
        }

        $filter = $this->hasOverBudget ? $this->filter : self::FILTER_ALL;

        return array_values(array_filter(
            $this->arrange($byParent, 0, 0, []),
            static fn (array $row): bool => match ($filter) {
                self::FILTER_OVER => $row['line']->available !== null && $row['line']->available < 0,
                self::FILTER_ON_TRACK => $row['line']->available === null || $row['line']->available >= 0,
                default => true,
            },
        ));
    }

    /** Cash-account balances less goal earmarks, or null while goals are off. */
    #[Computed]
    public function cashOnHand(): ?int
    {
        return config('budget.goals_enabled') === true
            ? app(CashOnHandCalculator::class)->for($this->authenticatedUser())
            : null;
    }

    #[Computed]
    public function drilldown(): ?BudgetLineSummaryDto
    {
        $plan = $this->plan;

        if ($plan === null || $this->drilldownId === null) {
            return null;
        }

        try {
            return app(BudgetPlanCalculator::class)->drilldown($plan, $this->drilldownId);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.monthly-budget');
    }

    /**
     * @param  array<int, list<BudgetLineSummaryDto>>  $byParent
     * @param  array<int, true>  $seen
     * @return list<array{line: BudgetLineSummaryDto, depth: int}>
     */
    private function arrange(array $byParent, int $parent, int $depth, array $seen): array
    {
        $rows = [];

        foreach ($byParent[$parent] ?? [] as $line) {
            if (isset($seen[$line->categoryId])) {
                continue;
            }

            $rows[] = ['line' => $line, 'depth' => $depth];

            foreach ($this->arrange($byParent, $line->categoryId, $depth + 1, $seen + [$line->categoryId => true]) as $child) {
                $rows[] = $child;
            }
        }

        return $rows;
    }

    private function loadMonth(): void
    {
        $user = $this->authenticatedUser();

        $month = $this->clampMonth($user, $this->month);

        $exists = BudgetPlan::query()
            ->where('user_id', $user->id)
            ->whereDate('period_start', $month)
            ->exists();

        $this->planId = $exists ? app(BudgetPlanManager::class)->open($user, $month)->id : null;

        $this->refreshFigures();
    }

    private function openViewedMonth(User $user): BudgetPlan
    {
        $plan = app(BudgetPlanManager::class)->open($user, $this->clampMonth($user, $this->month));
        $this->planId = $plan->id;

        return $plan;
    }

    private function loadPlan(User $user): BudgetPlan
    {
        return BudgetPlan::query()->where('user_id', $user->id)->findOrFail($this->planId);
    }

    private function loadLine(User $user, int $lineId): Budget
    {
        return $this->loadPlan($user)->lines()->where('user_id', $user->id)->findOrFail($lineId);
    }

    private function isSubcategory(User $user, Budget $line): bool
    {
        return Category::query()->where('user_id', $user->id)->whereKey($line->category_id)->value('parent_id') !== null;
    }

    private function attempt(Closure $write, string $errorKey = 'budget'): void
    {
        try {
            $write();
        } catch (BudgetPlanRefusedException $refused) {
            $this->addError($errorKey, $refused->getMessage());
        }

        $this->invalidateReadState();
    }

    private function invalidateReadState(): void
    {
        unset($this->plan, $this->summary, $this->rows, $this->hasOverBudget, $this->copySource, $this->drilldown, $this->cashOnHand); // @phpstan-ignore property.notFound
    }

    private function refreshFigures(): void
    {
        $this->invalidateReadState();

        $plan = $this->plan;
        $initialised = $plan !== null && $plan->initialised_at !== null;

        $this->totalInput = $initialised ? $this->dollars($plan->total_amount) : '';
        $this->incomeInput = $initialised ? $this->dollars($plan->expected_income) : '';
    }

    private function clampMonth(User $user, string $month): CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $month)
            : false;

        $viewed = $parsed === false ? $user->today()->startOfMonth() : $parsed;
        $bounds = $this->bounds;

        return match (true) {
            $viewed->lessThan($bounds['min']) => $bounds['min'],
            $viewed->greaterThan($bounds['max']) => $bounds['max'],
            default => $viewed,
        };
    }

    private function cents(string $value): ?int
    {
        $value = mb_trim($value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount < 0 || $amount > 1_000_000_000 ? null : (int) round($amount * 100);
    }

    private function dollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
