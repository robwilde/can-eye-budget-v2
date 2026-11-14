<?php

declare(strict_types=1);

namespace App\Services\Budgets;

use App\Enums\BudgetPeriod;
use App\Exceptions\BudgetPlanRefusedException;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\User;
use App\Support\Budget\AllocationTree;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The write side of the monthly budget. Every method takes the acting user and re-reads the
 * plan and lines it touches scoped to that user, so another user's record fails as not found.
 * A write and the rollover recompute that follows it share one database transaction.
 */
final readonly class BudgetPlanManager
{
    public function __construct(
        private BudgetPlanCalculator $calculator,
        private BudgetRolloverRecomputer $recomputer,
    ) {}

    /**
     * The user's plan for the month containing $month, created uninitialised with a zero line
     * for each visible category when it does not exist yet. Lines for categories added since
     * are filled in on a repeat call. New lines inherit the rollover choice of the latest
     * initialised earlier plan.
     */
    public function open(User $user, CarbonInterface $month): BudgetPlan
    {
        $start = CarbonImmutable::parse($month->toDateString())->startOfMonth();

        return $user->getConnection()->transaction(function () use ($user, $start): BudgetPlan {
            $this->lockUser($user);
            $plan = $this->findOrCreatePlan($user, $start);

            if ($this->ensureLines($plan) > 0 && $plan->initialised_at !== null) {
                $this->recomputer->recomputeFrom($plan);
            }

            return $plan;
        });
    }

    /**
     * Sets the month's total and expected income and marks the plan initialised. Calling it
     * again on an initialised plan updates the figures and keeps the original initialised time.
     */
    public function initialise(User $actor, BudgetPlan $plan, int $total, int $expectedIncome): BudgetPlan
    {
        if ($total < 0 || $expectedIncome < 0) {
            throw new BudgetPlanRefusedException('Budgeted spending and expected income cannot be negative.');
        }

        return $actor->getConnection()->transaction(function () use ($actor, $plan, $total, $expectedIncome): BudgetPlan {
            $this->lockUser($actor);
            $locked = $this->lockPlan($actor, $plan->id);

            $locked->update([
                'total_amount' => $total,
                'expected_income' => $expectedIncome,
                'initialised_at' => $locked->initialised_at ?? now(),
            ]);

            $this->recomputer->recomputeFrom($locked);

            return $locked->refresh();
        });
    }

    /**
     * Median monthly spend and income from the user's history, 0 when there is none.
     *
     * @return array{spending: int, income: int}
     */
    public function suggestFromHistory(User $user): array
    {
        return $this->calculator->historyMedians($user);
    }

    /**
     * Fills an uninitialised plan from the latest earlier initialised one: total, expected
     * income, allocations and rollover flags. Rolled-over amounts are recomputed, never copied.
     * Categories the target has no line for are skipped; new categories stay at 0.
     */
    public function copyFrom(User $actor, BudgetPlan $target): BudgetPlan
    {
        return $actor->getConnection()->transaction(function () use ($actor, $target): BudgetPlan {
            $this->lockUser($actor);
            $plan = $this->lockPlan($actor, $target->id);

            if ($plan->initialised_at !== null) {
                throw new BudgetPlanRefusedException('This month is already set up, so nothing can be copied into it.');
            }

            $source = BudgetPlan::query()
                ->where('user_id', $actor->id)
                ->whereNotNull('initialised_at')
                ->whereDate('period_start', '<', $plan->period_start)
                ->orderByDesc('period_start')
                ->first();

            if ($source === null) {
                throw new BudgetPlanRefusedException('There is no earlier month to copy from.');
            }

            $this->ensureLines($plan);
            $targetLines = $this->categoryLines($plan);
            $parents = $this->parents($actor->id);

            foreach ($targetLines as $targetLine) {
                if ($targetLine->limit_amount !== 0) {
                    $targetLine->update(['limit_amount' => 0]);
                }
            }

            foreach ($this->categoryLines($source) as $sourceLine) {
                $targetLines->get($sourceLine->category_id)?->update([
                    'limit_amount' => $sourceLine->limit_amount,
                    'rollover_enabled' => $sourceLine->rollover_enabled,
                    'rolled_over_amount' => 0,
                ]);
            }

            $copied = AllocationTree::fromLines($parents, $targetLines);

            foreach ($targetLines as $targetLine) {
                if ($targetLine->limit_amount === null && ! $copied->isCovered($targetLine->category_id)) {
                    throw new BudgetPlanRefusedException('A Shared subcategory cannot be copied without a parent budget line to draw on.');
                }
            }

            $this->reconcileParents($targetLines, $parents);

            $plan->update([
                'total_amount' => $source->total_amount,
                'expected_income' => $source->expected_income,
                'initialised_at' => now(),
            ]);

            $this->recomputer->recomputeFrom($plan);

            return $plan->refresh();
        });
    }

    /**
     * Sets a line's allocation; null makes a subcategory Shared. A change to a subcategory
     * moves each ancestor's allocation by the same amount, so the ancestor keeps its own reserve.
     */
    public function setAllocation(User $actor, Budget $line, ?int $amount): Budget
    {
        if ($amount !== null && $amount < 0) {
            throw new BudgetPlanRefusedException('An allocation cannot be negative.');
        }

        return $actor->getConnection()->transaction(function () use ($actor, $line, $amount): Budget {
            $this->lockUser($actor);
            $own = $this->ownLine($actor, $line);
            $plan = $this->lockPlan($actor, (int) $own->budget_plan_id);
            $lines = $this->categoryLines($plan);
            $parents = $this->parents($actor->id);

            $this->allocate($lines, $parents, $lines->get($own->category_id), $amount);
            $this->recomputer->recomputeFrom($plan);

            return $own->refresh();
        });
    }

    /**
     * Moves allocation from one category line to another in the same plan, leaving the total
     * unchanged. A parent can send only its own reserve, the part not given to its children.
     */
    public function moveMoney(User $actor, Budget $from, Budget $to, int $amount): void
    {
        if ($amount <= 0) {
            throw new BudgetPlanRefusedException('The amount to move must be more than zero.');
        }

        $actor->getConnection()->transaction(function () use ($actor, $from, $to, $amount): void {
            $this->lockUser($actor);
            $source = $this->ownLine($actor, $from);
            $destination = $this->ownLine($actor, $to);

            if ($source->budget_plan_id !== $destination->budget_plan_id) {
                throw new BudgetPlanRefusedException('Money can only move between categories in the same budget.');
            }

            $plan = $this->lockPlan($actor, (int) $source->budget_plan_id);
            $lines = $this->categoryLines($plan);
            $parents = $this->parents($actor->id);
            $sourceLine = $lines->get($source->category_id);
            $destinationLine = $lines->get($destination->category_id);

            if ($source->category_id === $destination->category_id
                || ($parents[$source->category_id] ?? null) === $destination->category_id
                || ($parents[$destination->category_id] ?? null) === $source->category_id) {
                throw new BudgetPlanRefusedException('Money cannot move between a category and itself, its parent or its child.');
            }

            if ($amount > $this->sendable($lines, $parents, $sourceLine)) {
                throw new BudgetPlanRefusedException('The category does not hold that much to move.');
            }

            $this->allocate($lines, $parents, $sourceLine, ($sourceLine->limit_amount ?? 0) - $amount);
            $destinationHeld = AllocationTree::fromLines($parents, $lines)->inclusive($destinationLine->category_id);
            $this->allocate($lines, $parents, $destinationLine, $destinationHeld + $amount);
            $this->recomputer->recomputeFrom($plan);
        });
    }

    /**
     * Turns rollover on or off for the line's category in its plan and every later plan, then
     * refreshes the carried amounts. The choice is standing: plans opened later inherit it.
     */
    public function setRollover(User $actor, Budget $line, bool $enabled): void
    {
        $actor->getConnection()->transaction(function () use ($actor, $line, $enabled): void {
            $this->lockUser($actor);
            $own = $this->ownLine($actor, $line);
            $plan = $this->lockPlan($actor, (int) $own->budget_plan_id);

            Budget::query()
                ->where('user_id', $actor->id)
                ->where('category_id', $own->category_id)
                ->whereIn('budget_plan_id', BudgetPlan::query()
                    ->where('user_id', $actor->id)
                    ->whereDate('period_start', '>=', $plan->period_start)
                    ->select('id'))
                ->update(['rollover_enabled' => $enabled]);

            $this->recomputer->recomputeFrom($plan);
        });
    }

    /**
     * Raises each numeric line to at least what the lines below it hold, deepest first, so a
     * line that exists only in this plan does not hide the allocations beneath it.
     *
     * @param  Collection<int, Budget>  $lines
     * @param  array<int, int|null>  $parents
     */
    private function reconcileParents(Collection $lines, array $parents): void
    {
        foreach ($lines->sortByDesc(fn (Budget $line): int => $this->depth($parents, $line->category_id)) as $line) {
            if ($line->limit_amount === null) {
                continue;
            }

            $held = AllocationTree::fromLines($parents, $lines)->held($line->category_id);

            if ($line->limit_amount < $held) {
                $line->update(['limit_amount' => $held]);
            }
        }
    }

    /**
     * @param  array<int, int|null>  $parents
     */
    private function depth(array $parents, int $categoryId): int
    {
        $level = 0;

        for ($next = $parents[$categoryId] ?? null; $next !== null && $level < 50; $next = $parents[$next] ?? null) {
            $level++;
        }

        return $level;
    }

    private function findOrCreatePlan(User $user, CarbonImmutable $start): BudgetPlan
    {
        $existing = $this->findPlan($user, $start);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return BudgetPlan::query()->create([
                'user_id' => $user->id,
                'period_start' => $start,
                'period_end' => $start->endOfMonth(),
                'total_amount' => 0,
                'expected_income' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->findPlan($user, $start) ?? throw new BudgetPlanRefusedException('The budget could not be opened.');
        }
    }

    private function findPlan(User $user, CarbonImmutable $start): ?BudgetPlan
    {
        return BudgetPlan::query()
            ->where('user_id', $user->id)
            ->whereDate('period_start', $start)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Adds a zero line for each visible category the plan lacks, returning how many it added. A
     * parent line added over existing child lines starts at what those children hold, so their
     * allocations stay counted.
     */
    private function ensureLines(BudgetPlan $plan): int
    {
        $lines = $this->categoryLines($plan);

        $missing = Category::query()
            ->where('user_id', $plan->user_id)
            ->visible()
            ->get(['id', 'name'])
            ->reject(static fn (Category $category): bool => $lines->has($category->id));

        if ($missing->isEmpty()) {
            return 0;
        }

        $inherited = $this->inheritedRollover($plan);
        $parents = $this->parents($plan->user_id);
        $limits = $this->newLineLimits($parents, $missing->modelKeys(), $lines);
        $now = now();

        $rows = $missing
            ->map(static fn (Category $category): array => [
                'user_id' => $plan->user_id,
                'budget_plan_id' => $plan->id,
                'category_id' => $category->id,
                'name' => $category->name,
                'limit_amount' => $limits[$category->id] ?? 0,
                'period' => BudgetPeriod::Monthly->value,
                'start_date' => $plan->period_start->toDateString(),
                'end_date' => $plan->period_end->toDateString(),
                'rollover_enabled' => in_array($category->id, $inherited, true),
                'rolled_over_amount' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        Budget::query()->insert($rows);

        $this->reconcileParents($this->categoryLines($plan), $parents);

        return count($rows);
    }

    /**
     * Starting allocation of each category about to get a line: what the lines below it hold,
     * counting lines being added with it, resolved from the lowest level up.
     *
     * @param  array<int, int|null>  $parents
     * @param  list<int>  $missingIds
     * @param  Collection<int, Budget>  $lines  the plan's existing lines keyed by category id
     * @return array<int, int>
     */
    private function newLineLimits(array $parents, array $missingIds, Collection $lines): array
    {
        $limits = [];

        foreach ($lines as $line) {
            $limits[$line->category_id] = $line->limit_amount;
        }

        usort($missingIds, fn (int $a, int $b): int => $this->depth($parents, $b) <=> $this->depth($parents, $a));

        $added = [];

        foreach ($missingIds as $id) {
            $added[$id] = $limits[$id] = (new AllocationTree($parents, $limits))->held($id);
        }

        return $added;
    }

    /**
     * @return list<int>
     */
    private function inheritedRollover(BudgetPlan $plan): array
    {
        $previous = BudgetPlan::query()
            ->where('user_id', $plan->user_id)
            ->whereNotNull('initialised_at')
            ->whereDate('period_start', '<', $plan->period_start)
            ->orderByDesc('period_start')
            ->first();

        if ($previous === null) {
            return [];
        }

        return $previous->lines()
            ->where('user_id', $plan->user_id)
            ->where('rollover_enabled', true)
            ->whereNotNull('category_id')
            ->pluck('category_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Serialises this user's budget writes. It runs before any other read in the transaction so
     * the data each write works from is read after earlier writers have committed.
     */
    private function lockUser(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    private function lockPlan(User $actor, int $planId): BudgetPlan
    {
        return BudgetPlan::query()
            ->where('user_id', $actor->id)
            ->lockForUpdate()
            ->findOrFail($planId);
    }

    private function ownLine(User $actor, Budget $line): Budget
    {
        $own = Budget::query()
            ->where('user_id', $actor->id)
            ->whereNotNull('budget_plan_id')
            ->findOrFail($line->id);

        if ($own->category_id === null) {
            throw new BudgetPlanRefusedException('Uncategorized has no allocation of its own.');
        }

        return $own;
    }

    /**
     * @return Collection<int, Budget> the plan's lines keyed by category id
     */
    private function categoryLines(BudgetPlan $plan): Collection
    {
        return $plan->lines()
            ->where('user_id', $plan->user_id)
            ->whereNotNull('category_id')
            ->get()
            ->keyBy('category_id');
    }

    /**
     * @return array<int, int|null> parent category id by category id, for the user's categories
     */
    private function parents(int $userId): array
    {
        /** @var array<int, int|null> $parents */
        $parents = Category::query()->where('user_id', $userId)->pluck('parent_id', 'id')->all();

        return $parents;
    }

    /**
     * What the line can give away: its allocation less what the lines below it hold, so a leaf
     * sends all of it and a parent only its own reserve. A Shared line holds nothing.
     *
     * @param  Collection<int, Budget>  $lines
     * @param  array<int, int|null>  $parents
     */
    private function sendable(Collection $lines, array $parents, Budget $line): int
    {
        return max(0, ($line->limit_amount ?? 0) - AllocationTree::fromLines($parents, $lines)->held($line->category_id));
    }

    /**
     * Writes the allocation and shifts each numeric ancestor by the change in what the line
     * holds, counting a Shared line as what is dedicated below it. The lines are the in-memory
     * models of this write, so later calls see earlier ones.
     *
     * @param  Collection<int, Budget>  $lines
     * @param  array<int, int|null>  $parents
     */
    private function allocate(Collection $lines, array $parents, Budget $line, ?int $amount): void
    {
        $tree = AllocationTree::fromLines($parents, $lines);
        $held = $tree->held($line->category_id);

        if ($amount === null && ($parents[$line->category_id] ?? null) === null) {
            throw new BudgetPlanRefusedException('Only a subcategory can be Shared.');
        }

        if ($amount === null && ! $tree->isCovered($line->category_id)) {
            throw new BudgetPlanRefusedException('A subcategory can only be Shared when an ancestor has a budget line to draw on.');
        }

        if ($amount !== null && $amount < $held) {
            throw new BudgetPlanRefusedException('A category cannot be given less than its subcategories hold.');
        }

        $change = ($amount ?? $held) - $tree->inclusive($line->category_id);
        $line->update(['limit_amount' => $amount]);

        foreach ($tree->numericAncestors($line->category_id) as $ancestorId) {
            $ancestor = $lines->get($ancestorId);
            $ancestor->update(['limit_amount' => max(0, $ancestor->limit_amount + $change)]);
        }
    }
}
