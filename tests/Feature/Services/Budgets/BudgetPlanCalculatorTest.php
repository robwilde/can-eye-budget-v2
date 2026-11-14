<?php

declare(strict_types=1);

use App\DTOs\BudgetLineSummaryDto;
use App\DTOs\TransactionSplitLine;
use App\Enums\BudgetLineStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Budgets\BudgetPlanCalculator;
use App\Services\Budgets\BudgetRolloverRecomputer;
use App\Services\Transactions\TransactionSplitter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    $this->travelTo('2026-03-22 12:00:00');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function budgetCalcPlan(User $user, string $month, bool $initialised = true, int $total = 0, int $expectedIncome = 0): BudgetPlan
{
    $start = CarbonImmutable::parse($month.'-01');

    return BudgetPlan::factory()->for($user)->create([
        'period_start' => $start->toDateString(),
        'period_end' => $start->endOfMonth()->toDateString(),
        'total_amount' => $total,
        'expected_income' => $expectedIncome,
        'initialised_at' => $initialised ? $start : null,
    ]);
}

function budgetCalcLine(BudgetPlan $plan, Category $category, ?int $limit, bool $rollover = false): Budget
{
    return Budget::factory()->inPlan($plan)->withCategory($category)->create([
        'limit_amount' => $limit,
        'rollover_enabled' => $rollover,
    ]);
}

function budgetCalcCategory(User $user, string $name = 'Groceries', ?Category $parent = null): Category
{
    return Category::factory()->for($user)->create(['name' => $name, 'parent_id' => $parent?->id]);
}

function budgetCalcSpend(User $user, Account $account, ?Category $category, int $cents, string $date, TransactionDirection $direction = TransactionDirection::Debit): Transaction
{
    return Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $category?->id,
        'amount' => $cents,
        'direction' => $direction,
        'post_date' => $date,
    ]);
}

function budgetCalcLineFor(BudgetPlan $plan, Budget $line): BudgetLineSummaryDto
{
    return collect(app(BudgetPlanCalculator::class)->summarise($plan)->lines)->firstWhere('budgetId', $line->id);
}

function budgetCalcRecompute(BudgetPlan $plan): void
{
    app(BudgetRolloverRecomputer::class)->recomputeFrom($plan);
}

it('carries only a surplus into the next month', function () {
    $groceries = budgetCalcCategory($this->user);
    $january = budgetCalcPlan($this->user, '2026-01');
    $february = budgetCalcPlan($this->user, '2026-02');
    budgetCalcLine($january, $groceries, 40000, rollover: true);
    $februaryLine = budgetCalcLine($february, $groceries, 40000, rollover: true);

    budgetCalcSpend($this->user, $this->account, $groceries, 30000, '2026-01-10');
    budgetCalcRecompute($january);

    expect(budgetCalcLineFor($february, $februaryLine)->available)->toBe(50000);

    budgetCalcSpend($this->user, $this->account, $groceries, 20000, '2026-01-20');
    budgetCalcRecompute($january);

    $line = budgetCalcLineFor($february, $februaryLine);

    expect($line->available)->toBe(40000)
        ->and($line->rolledOver)->toBe(0);
});

it('flags near limit at 90 percent and over budget below zero', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 10000);

    budgetCalcSpend($this->user, $this->account, $groceries, 9500, '2026-03-05');

    expect(budgetCalcLineFor($plan, $line)->status)->toBe(BudgetLineStatus::NearLimit);

    budgetCalcSpend($this->user, $this->account, $groceries, 1500, '2026-03-06');

    $over = budgetCalcLineFor($plan, $line);

    expect($over->status)->toBe(BudgetLineStatus::OverBudget)
        ->and($over->available)->toBe(-1000);
});

it('counts only tracked to untracked transfers as budget spend', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 100000);
    $second = Account::factory()->for($this->user)->create();
    $untracked = Account::factory()->for($this->user)->untracked()->create();

    $pair = function (Account $from, Account $to, int $cents) use ($groceries): void {
        $out = budgetCalcSpend($this->user, $from, $groceries, $cents, '2026-03-10');
        $in = budgetCalcSpend($this->user, $to, $groceries, $cents, '2026-03-10', TransactionDirection::Credit);
        $out->update(['transfer_pair_id' => $in->id]);
        $in->update(['transfer_pair_id' => $out->id]);
    };

    $pair($this->account, $second, 100000);

    expect(budgetCalcLineFor($plan, $line)->actual)->toBe(0);

    $pair($this->account, $untracked, 20000);

    expect(budgetCalcLineFor($plan, $line)->actual)->toBe(20000);
});

it('floors category actual at zero after refunds', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);

    budgetCalcSpend($this->user, $this->account, $groceries, 5000, '2026-03-05');
    budgetCalcSpend($this->user, $this->account, $groceries, 8000, '2026-03-06', TransactionDirection::Credit);

    $summary = budgetCalcLineFor($plan, $line);

    expect($summary->actual)->toBe(0)
        ->and($summary->available)->toBe(40000);
});

it('skips uninitialised months without breaking the rollover chain', function () {
    $groceries = budgetCalcCategory($this->user);
    $january = budgetCalcPlan($this->user, '2026-01');
    $february = budgetCalcPlan($this->user, '2026-02', initialised: false);
    $march = budgetCalcPlan($this->user, '2026-03');
    budgetCalcLine($january, $groceries, 40000, rollover: true);
    $februaryLine = budgetCalcLine($february, $groceries, 40000, rollover: true);
    $marchLine = budgetCalcLine($march, $groceries, 40000, rollover: true);

    budgetCalcSpend($this->user, $this->account, $groceries, 30000, '2026-01-10');
    budgetCalcRecompute($january);

    expect(budgetCalcLineFor($march, $marchLine)->rolledOver)->toBe(10000)
        ->and($februaryLine->fresh()->rolled_over_amount)->toBe(0);
});

it('ignores another user transactions when computing actuals', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();

    budgetCalcSpend($other, $otherAccount, budgetCalcCategory($other), 7000, '2026-03-05');
    budgetCalcSpend($other, $otherAccount, $groceries, 9000, '2026-03-06');

    expect(budgetCalcLineFor($plan, $line)->actual)->toBe(0)
        ->and(app(BudgetPlanCalculator::class)->summarise($plan)->spent)->toBe(0);
});

it('reports over-allocation using plan figures without rollover', function () {
    $groceries = budgetCalcCategory($this->user);
    $dining = budgetCalcCategory($this->user, 'Dining');
    $plan = budgetCalcPlan($this->user, '2026-03', total: 200000);
    budgetCalcLine($plan, $groceries, 130000, rollover: true)->update(['rolled_over_amount' => 10000]);
    budgetCalcLine($plan, $dining, 100000);

    $summary = app(BudgetPlanCalculator::class)->summarise($plan);

    expect($summary->allocated)->toBe(230000)
        ->and($summary->availableToAllocate)->toBe(-30000)
        ->and($summary->overAllocated)->toBeTrue();
});

it('does not flag an allocation equal to the total', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03', total: 100000);
    budgetCalcLine($plan, $groceries, 100000);

    $summary = app(BudgetPlanCalculator::class)->summarise($plan);

    expect($summary->availableToAllocate)->toBe(0)
        ->and($summary->overAllocated)->toBeFalse();
});

it('counts a parent allocation once and rolls subcategory spend into the parent', function () {
    $food = budgetCalcCategory($this->user, 'Food');
    $groceries = budgetCalcCategory($this->user, 'Groceries', $food);
    $plan = budgetCalcPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetCalcLine($plan, $food, 60000);
    $groceriesLine = budgetCalcLine($plan, $groceries, 10000);

    budgetCalcSpend($this->user, $this->account, $groceries, 4000, '2026-03-05');
    budgetCalcSpend($this->user, $this->account, $food, 1000, '2026-03-06');

    $summary = app(BudgetPlanCalculator::class)->summarise($plan);

    expect($summary->allocated)->toBe(60000)
        ->and(budgetCalcLineFor($plan, $foodLine)->actual)->toBe(5000)
        ->and(budgetCalcLineFor($plan, $groceriesLine)->actual)->toBe(4000)
        ->and(budgetCalcLineFor($plan, $groceriesLine)->parentCategoryId)->toBe($food->id);
});

it('treats a subcategory without an allocation as shared and carries nothing from it', function () {
    $food = budgetCalcCategory($this->user, 'Food');
    $groceries = budgetCalcCategory($this->user, 'Groceries', $food);
    $january = budgetCalcPlan($this->user, '2026-01', total: 60000);
    $february = budgetCalcPlan($this->user, '2026-02', total: 60000);
    budgetCalcLine($january, $food, 60000, rollover: true);
    budgetCalcLine($january, $groceries, null, rollover: true);
    budgetCalcLine($february, $food, 60000, rollover: true);
    $februaryShared = budgetCalcLine($february, $groceries, null, rollover: true);

    budgetCalcSpend($this->user, $this->account, $groceries, 4000, '2026-01-05');
    budgetCalcRecompute($january);

    $shared = budgetCalcLineFor($february, $februaryShared);

    expect($shared->isShared())->toBeTrue()
        ->and($shared->available)->toBeNull()
        ->and($shared->percentSpent)->toBeNull()
        ->and($shared->status)->toBe(BudgetLineStatus::OnTrack)
        ->and($shared->rolledOver)->toBe(0)
        ->and($februaryShared->fresh()->rolled_over_amount)->toBe(0)
        ->and(app(BudgetPlanCalculator::class)->summarise($january)->allocated)->toBe(60000);
});

it('stops a carry when rollover is off on either side', function () {
    $groceries = budgetCalcCategory($this->user);
    $dining = budgetCalcCategory($this->user, 'Dining');
    $january = budgetCalcPlan($this->user, '2026-01');
    $february = budgetCalcPlan($this->user, '2026-02');
    budgetCalcLine($january, $groceries, 40000, rollover: false);
    budgetCalcLine($january, $dining, 40000, rollover: true);
    $groceriesFebruary = budgetCalcLine($february, $groceries, 40000, rollover: true);
    $diningFebruary = budgetCalcLine($february, $dining, 40000, rollover: false);

    budgetCalcRecompute($january);

    expect($groceriesFebruary->fresh()->rolled_over_amount)->toBe(0)
        ->and($diningFebruary->fresh()->rolled_over_amount)->toBe(0);
});

it('rewrites stale rolled over amounts down the chain', function () {
    $groceries = budgetCalcCategory($this->user);
    $january = budgetCalcPlan($this->user, '2026-01');
    $february = budgetCalcPlan($this->user, '2026-02');
    $march = budgetCalcPlan($this->user, '2026-03');
    budgetCalcLine($january, $groceries, 40000, rollover: true);
    $februaryLine = budgetCalcLine($february, $groceries, 40000, rollover: true);
    $marchLine = budgetCalcLine($march, $groceries, 40000, rollover: true);

    budgetCalcSpend($this->user, $this->account, $groceries, 30000, '2026-01-10');
    budgetCalcSpend($this->user, $this->account, $groceries, 10000, '2026-02-10');
    budgetCalcRecompute($january);

    expect($februaryLine->fresh()->rolled_over_amount)->toBe(10000)
        ->and($marchLine->fresh()->rolled_over_amount)->toBe(40000);

    Transaction::query()->whereDate('post_date', '2026-01-10')->update(['amount' => 40000]);
    budgetCalcRecompute($january);

    expect($februaryLine->fresh()->rolled_over_amount)->toBe(0)
        ->and($marchLine->fresh()->rolled_over_amount)->toBe(30000);
});

it('recomputes from a middle plan using the earlier plan as the carry source', function () {
    $groceries = budgetCalcCategory($this->user);
    $january = budgetCalcPlan($this->user, '2026-01');
    $february = budgetCalcPlan($this->user, '2026-02');
    $other = budgetCalcPlan(User::factory()->create(), '2026-01');
    budgetCalcLine($january, $groceries, 40000, rollover: true);
    $februaryLine = budgetCalcLine($february, $groceries, 40000, rollover: true);

    budgetCalcSpend($this->user, $this->account, $groceries, 25000, '2026-01-10');
    budgetCalcRecompute($february);

    expect($februaryLine->fresh()->rolled_over_amount)->toBe(15000)
        ->and($other->lines()->count())->toBe(0);
});

it('reports percent spent and status edges for unbudgeted lines', function () {
    $groceries = budgetCalcCategory($this->user);
    $dining = budgetCalcCategory($this->user, 'Dining');
    $plan = budgetCalcPlan($this->user, '2026-03');
    $idle = budgetCalcLine($plan, $groceries, 0);
    $unbudgeted = budgetCalcLine($plan, $dining, 0);

    budgetCalcSpend($this->user, $this->account, $dining, 2500, '2026-03-05');

    $idleSummary = budgetCalcLineFor($plan, $idle);
    $unbudgetedSummary = budgetCalcLineFor($plan, $unbudgeted);

    expect($idleSummary->percentSpent)->toBe(0)
        ->and($idleSummary->status)->toBe(BudgetLineStatus::OnTrack)
        ->and($unbudgetedSummary->percentSpent)->toBe(100)
        ->and($unbudgetedSummary->status)->toBe(BudgetLineStatus::UnbudgetedSpend);
});

it('treats exactly 90 percent as near limit and exactly the allocation as not over', function () {
    $groceries = budgetCalcCategory($this->user);
    $dining = budgetCalcCategory($this->user, 'Dining');
    $plan = budgetCalcPlan($this->user, '2026-03');
    $ninety = budgetCalcLine($plan, $groceries, 10000);
    $full = budgetCalcLine($plan, $dining, 10000);

    budgetCalcSpend($this->user, $this->account, $groceries, 9000, '2026-03-05');
    budgetCalcSpend($this->user, $this->account, $dining, 10000, '2026-03-05');

    expect(budgetCalcLineFor($plan, $ninety)->status)->toBe(BudgetLineStatus::NearLimit)
        ->and(budgetCalcLineFor($plan, $full)->status)->toBe(BudgetLineStatus::NearLimit)
        ->and(budgetCalcLineFor($plan, $full)->available)->toBe(0);
});

it('measures spend against allocation plus the amount rolled over', function () {
    $groceries = budgetCalcCategory($this->user);
    $january = budgetCalcPlan($this->user, '2026-01');
    $february = budgetCalcPlan($this->user, '2026-02');
    budgetCalcLine($january, $groceries, 10000, rollover: true);
    $februaryLine = budgetCalcLine($february, $groceries, 10000, rollover: true);

    budgetCalcRecompute($january);
    budgetCalcSpend($this->user, $this->account, $groceries, 15000, '2026-02-05');

    $summary = budgetCalcLineFor($february, $februaryLine);

    expect($summary->rolledOver)->toBe(10000)
        ->and($summary->percentSpent)->toBe(75)
        ->and($summary->status)->toBe(BudgetLineStatus::OnTrack);
});

it('sums spend and income across top level categories', function () {
    $groceries = budgetCalcCategory($this->user);
    $income = budgetCalcCategory($this->user, 'Income');
    $salary = budgetCalcCategory($this->user, 'Salary', $income);
    $plan = budgetCalcPlan($this->user, '2026-03', expectedIncome: 400000);

    budgetCalcSpend($this->user, $this->account, $groceries, 30000, '2026-03-05');
    budgetCalcSpend($this->user, $this->account, $groceries, 5000, '2026-03-06', TransactionDirection::Credit);
    budgetCalcSpend($this->user, $this->account, null, 7000, '2026-03-07');
    budgetCalcSpend($this->user, $this->account, $salary, 300000, '2026-03-08', TransactionDirection::Credit);
    budgetCalcSpend($this->user, $this->account, null, 20000, '2026-03-09', TransactionDirection::Credit);
    budgetCalcSpend($this->user, $this->account, $groceries, 99999, '2026-04-01');

    $summary = app(BudgetPlanCalculator::class)->summarise($plan);

    expect($summary->spent)->toBe(32000)
        ->and($summary->actualIncome)->toBe(320000)
        ->and($summary->incomePercent)->toBe(80);
});

it('reads income surplus as 100 percent when none was expected', function () {
    $salary = budgetCalcCategory($this->user, 'Salary');
    $plan = budgetCalcPlan($this->user, '2026-03', expectedIncome: 0);
    $empty = budgetCalcPlan($this->user, '2026-02', expectedIncome: 0);

    budgetCalcSpend($this->user, $this->account, $salary, 100000, '2026-03-08', TransactionDirection::Credit);

    expect(app(BudgetPlanCalculator::class)->summarise($plan)->incomePercent)->toBe(100)
        ->and(app(BudgetPlanCalculator::class)->summarise($empty)->incomePercent)->toBe(0);
});

it('leaves excluded transactions and untracked accounts out of spend', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);
    $untracked = Account::factory()->for($this->user)->untracked()->create();

    budgetCalcSpend($this->user, $this->account, $groceries, 3000, '2026-03-05')->update(['excluded' => true]);
    budgetCalcSpend($this->user, $untracked, $groceries, 4000, '2026-03-06');
    budgetCalcSpend($this->user, $this->account, $groceries, 1000, '2026-03-31');
    budgetCalcSpend($this->user, $this->account, $groceries, 2000, '2026-02-28');

    expect(budgetCalcLineFor($plan, $line)->actual)->toBe(1000);
});

it('converts spend to the base currency when multi-currency is on', function () {
    config(['budget.multi_currency' => true, 'services.exchange_rates.enabled' => false]);
    $user = User::factory()->create(['base_currency' => 'USD']);
    $euro = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $groceries = budgetCalcCategory($user);
    $plan = budgetCalcPlan($user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);
    ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2026-03-10', 'rate' => 1.5]);

    budgetCalcSpend($user, $euro, $groceries, 10000, '2026-03-10')->update(['currency' => 'EUR']);

    expect(budgetCalcLineFor($plan, $line)->actual)->toBe(15000);
});

it('builds the drilldown from subcategories with history and bills reserved', function () {
    $food = budgetCalcCategory($this->user, 'Food');
    $groceries = budgetCalcCategory($this->user, 'Groceries', $food);
    $other = budgetCalcCategory($this->user, 'Fuel');
    $plan = budgetCalcPlan($this->user, '2026-03');
    $foodLine = budgetCalcLine($plan, $food, 60000);

    budgetCalcSpend($this->user, $this->account, $food, 100, '2026-03-01');
    budgetCalcSpend($this->user, $this->account, $groceries, 200, '2026-03-02');
    $third = budgetCalcSpend($this->user, $this->account, $food, 300, '2026-03-03');
    $newest = budgetCalcSpend($this->user, $this->account, $groceries, 400, '2026-03-04');
    budgetCalcSpend($this->user, $this->account, $other, 9999, '2026-03-05');

    budgetCalcSpend($this->user, $this->account, $food, 1000, '2026-01-10');
    budgetCalcSpend($this->user, $this->account, $groceries, 3000, '2026-02-10');
    budgetCalcSpend($this->user, $this->account, $groceries, 5000, '2025-12-10');
    budgetCalcSpend($this->user, $this->account, $groceries, 50000, '2024-01-10');

    PlannedTransaction::factory()->noRepeat()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 15000,
        'start_date' => '2026-03-25',
    ]);
    $paid = PlannedTransaction::factory()->noRepeat()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $food->id,
        'amount' => 7000,
        'start_date' => '2026-03-15',
    ]);
    $linked = budgetCalcSpend($this->user, $this->account, $food, 7000, '2026-03-16');
    $linked->update(['planned_transaction_id' => $paid->id]);
    PlannedTransaction::factory()->noRepeat()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $other->id,
        'amount' => 4000,
        'start_date' => '2026-03-26',
    ]);

    $drilldown = app(BudgetPlanCalculator::class)->drilldown($plan, $foodLine->id);

    expect(collect($drilldown->recentTransactions)->pluck('id')->all())->toBe([$linked->id, $newest->id, $third->id])
        ->and($drilldown->averageMonthlySpend)->toBe(3000)
        ->and($drilldown->medianMonthlySpend)->toBe(3000)
        ->and($drilldown->billsReserved)->toBe(15000);
});

it('gives a zero average and median when there is no history', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 10000);

    $drilldown = app(BudgetPlanCalculator::class)->drilldown($plan, $line->id);

    expect($drilldown->averageMonthlySpend)->toBe(0)
        ->and($drilldown->medianMonthlySpend)->toBe(0)
        ->and($drilldown->billsReserved)->toBe(0)
        ->and($drilldown->recentTransactions)->toBe([]);
});

it('suggests a daily spend only in the current month and never below zero', function () {
    $groceries = budgetCalcCategory($this->user);
    $dining = budgetCalcCategory($this->user, 'Dining');
    $current = budgetCalcPlan($this->user, '2026-03');
    $past = budgetCalcPlan($this->user, '2026-02');
    $currentLine = budgetCalcLine($current, $groceries, 30000);
    $overLine = budgetCalcLine($current, $dining, 1000);
    $pastLine = budgetCalcLine($past, $groceries, 30000);

    budgetCalcSpend($this->user, $this->account, $dining, 5000, '2026-03-02');

    $calculator = app(BudgetPlanCalculator::class);

    expect($calculator->drilldown($current, $currentLine->id)->suggestedDailySpend)->toBe(3000)
        ->and($calculator->drilldown($current, $overLine->id)->suggestedDailySpend)->toBe(0)
        ->and($calculator->drilldown($past, $pastLine->id)->suggestedDailySpend)->toBeNull();
});

it('refuses a drilldown for a line outside the plan', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $otherPlan = budgetCalcPlan($this->user, '2026-02');
    $foreignLine = budgetCalcLine($otherPlan, $groceries, 10000);

    expect(fn () => app(BudgetPlanCalculator::class)->drilldown($plan, $foreignLine->id))
        ->toThrow(ModelNotFoundException::class);
});

it('reserves bills only from the plans counted for the user', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 10000);
    $untracked = Account::factory()->for($this->user)->untracked()->create();

    PlannedTransaction::factory()->monthly()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 2500,
        'start_date' => '2026-01-10',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);
    PlannedTransaction::factory()->monthly()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 1000,
        'direction' => TransactionDirection::Credit,
        'start_date' => '2026-01-10',
    ]);
    PlannedTransaction::factory()->monthly()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transfer_to_account_id' => $untracked->id,
        'category_id' => $groceries->id,
        'amount' => 4000,
        'start_date' => '2026-01-12',
    ]);
    PlannedTransaction::factory()->monthly()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 9999,
        'start_date' => '2026-01-10',
        'is_active' => false,
    ]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(6500);
});

it('counts split lines of a transfer category row under their own categories', function () {
    $groceries = budgetCalcCategory($this->user);
    $fuel = budgetCalcCategory($this->user, 'Fuel');
    $transfer = budgetCalcCategory($this->user, 'Transfer');
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);

    $split = budgetCalcSpend($this->user, $this->account, $transfer, 10000, '2026-03-10');
    $split->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => 7000, 'position' => 0],
        ['category_id' => $fuel->id, 'amount' => 3000, 'position' => 1],
    ]);

    expect(budgetCalcLineFor($plan, $line)->actual)->toBe(7000)
        ->and(app(BudgetPlanCalculator::class)->summarise($plan)->spent)->toBe(10000);
});

it('leaves a transfer split out of spend even when the row is not a transfer', function () {
    $groceries = budgetCalcCategory($this->user);
    $transfer = budgetCalcCategory($this->user, 'Transfer');
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);

    $split = budgetCalcSpend($this->user, $this->account, $groceries, 10000, '2026-03-10');
    $split->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => 6000, 'position' => 0],
        ['category_id' => $transfer->id, 'amount' => 4000, 'position' => 1],
    ]);

    expect(budgetCalcLineFor($plan, $line)->actual)->toBe(6000)
        ->and(app(BudgetPlanCalculator::class)->summarise($plan)->spent)->toBe(6000);
});

it('reserves a long running daily bill for the whole month', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 400000);

    PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 1000,
        'frequency' => RecurrenceFrequency::Everyday,
        'start_date' => '2022-01-01',
    ]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(31000);
});

it('lets one posting pay only one occurrence of a short cadence bill', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 400000);

    $daily = PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 1000,
        'frequency' => RecurrenceFrequency::Everyday,
        'start_date' => '2026-01-01',
    ]);
    budgetCalcSpend($this->user, $this->account, $groceries, 1000, '2026-03-10')->update(['planned_transaction_id' => $daily->id]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(30000);
});

it('keeps a posting on its nearest occurrence even when that one is outside the month', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 400000);

    $daily = PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 1000,
        'frequency' => RecurrenceFrequency::Everyday,
        'start_date' => '2026-01-01',
    ]);
    budgetCalcSpend($this->user, $this->account, $groceries, 1000, '2026-02-28')->update(['planned_transaction_id' => $daily->id]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(31000);
});

it('does not treat an excluded posting as paying a bill', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);

    $bill = PlannedTransaction::factory()->noRepeat()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 15000,
        'start_date' => '2026-03-25',
    ]);
    $payment = budgetCalcSpend($this->user, $this->account, $groceries, 15000, '2026-03-25');
    $payment->update(['planned_transaction_id' => $bill->id]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(0);

    $payment->update(['excluded' => true]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(15000);
});

it('keeps a fully refunded month in the drilldown history as zero', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 10000);

    budgetCalcSpend($this->user, $this->account, $groceries, 10000, '2026-01-10');
    budgetCalcSpend($this->user, $this->account, $groceries, 2000, '2026-02-10');
    budgetCalcSpend($this->user, $this->account, $groceries, 5000, '2026-02-12', TransactionDirection::Credit);

    $drilldown = app(BudgetPlanCalculator::class)->drilldown($plan, $line->id);

    expect($drilldown->averageMonthlySpend)->toBe(5000)
        ->and($drilldown->medianMonthlySpend)->toBe(5000);
});

it('reserves a bill again when its payment is later linked as a tracked transfer', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);
    $second = Account::factory()->for($this->user)->create();

    $bill = PlannedTransaction::factory()->noRepeat()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 15000,
        'start_date' => '2026-03-25',
    ]);
    $payment = budgetCalcSpend($this->user, $this->account, $groceries, 15000, '2026-03-25');
    $payment->update(['planned_transaction_id' => $bill->id]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(0);

    $mirror = budgetCalcSpend($this->user, $second, $groceries, 15000, '2026-03-25', TransactionDirection::Credit);
    $payment->update(['transfer_pair_id' => $mirror->id]);
    $mirror->update(['transfer_pair_id' => $payment->id]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(15000);
});

it('reserves a bill again when its linked payment is changed to income', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);

    $bill = PlannedTransaction::factory()->noRepeat()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 15000,
        'start_date' => '2026-03-25',
    ]);
    $payment = budgetCalcSpend($this->user, $this->account, $groceries, 15000, '2026-03-25');
    $payment->update(['planned_transaction_id' => $bill->id]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(0);

    $payment->update(['direction' => TransactionDirection::Credit]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(15000);
});

it('keeps a bill paid when its payment is split into child transactions', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);

    $bill = PlannedTransaction::factory()->noRepeat()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 15000,
        'start_date' => '2026-03-25',
    ]);
    $payment = budgetCalcSpend($this->user, $this->account, $groceries, 15000, '2026-03-25');
    $payment->update(['planned_transaction_id' => $bill->id]);

    app(TransactionSplitter::class)->save($this->user->id, $payment->id, [
        new TransactionSplitLine(amount: 10000, categoryId: $groceries->id),
        new TransactionSplitLine(amount: 5000, categoryId: $groceries->id),
    ]);

    $drilldown = app(BudgetPlanCalculator::class)->drilldown($plan, $line->id);

    expect($drilldown->actual)->toBe(15000)
        ->and($drilldown->billsReserved)->toBe(0);
});

it('still reserves a bill whose split payment is excluded by the user', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 40000);

    $bill = PlannedTransaction::factory()->noRepeat()->for($this->user)->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 15000,
        'start_date' => '2026-03-25',
    ]);
    $payment = budgetCalcSpend($this->user, $this->account, $groceries, 15000, '2026-03-25');
    $payment->update(['planned_transaction_id' => $bill->id, 'excluded' => true]);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(15000);
});

it('counts an allocation once when a hidden intermediate category sits between the lines', function () {
    $food = budgetCalcCategory($this->user, 'Food');
    $dining = budgetCalcCategory($this->user, 'Dining', $food);
    $cafes = budgetCalcCategory($this->user, 'Cafes', $dining);
    $plan = budgetCalcPlan($this->user, '2026-03', total: 100000);
    budgetCalcLine($plan, $food, 6000);
    budgetCalcLine($plan, $cafes, 6000);

    expect(app(BudgetPlanCalculator::class)->summarise($plan)->allocated)->toBe(6000);
});

it('counts an allocation once when the intermediate line is Shared', function () {
    $food = budgetCalcCategory($this->user, 'Food');
    $dining = budgetCalcCategory($this->user, 'Dining', $food);
    $cafes = budgetCalcCategory($this->user, 'Cafes', $dining);
    $plan = budgetCalcPlan($this->user, '2026-03', total: 100000);
    budgetCalcLine($plan, $food, 6000);
    budgetCalcLine($plan, $dining, null);
    budgetCalcLine($plan, $cafes, 6000);

    expect(app(BudgetPlanCalculator::class)->summarise($plan)->allocated)->toBe(6000);
});

it('shows the current category name on monthly lines', function () {
    $groceries = budgetCalcCategory($this->user);
    $plan = budgetCalcPlan($this->user, '2026-03');
    $line = budgetCalcLine($plan, $groceries, 10000);
    $groceries->update(['name' => 'Supermarket']);

    $calculator = app(BudgetPlanCalculator::class);

    expect(budgetCalcLineFor($plan, $line)->name)->toBe('Supermarket')
        ->and($calculator->drilldown($plan, $line->id)->name)->toBe('Supermarket');
});
