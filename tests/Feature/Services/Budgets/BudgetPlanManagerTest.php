<?php

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Exceptions\BudgetPlanRefusedException;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Budgets\BudgetPlanCalculator;
use App\Services\Budgets\BudgetPlanManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    $this->travelTo('2026-03-22 12:00:00');
    $this->user = User::factory()->create();
    $this->manager = app(BudgetPlanManager::class);
});

function budgetMgrPlan(User $user, string $month, bool $initialised = true, int $total = 0, int $expectedIncome = 0): BudgetPlan
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

function budgetMgrCategory(User $user, string $name, ?Category $parent = null): Category
{
    return Category::factory()->for($user)->create(['name' => $name, 'parent_id' => $parent?->id]);
}

function budgetMgrLine(BudgetPlan $plan, Category $category, ?int $limit, bool $rollover = false): Budget
{
    return Budget::factory()->inPlan($plan)->withCategory($category)->create([
        'limit_amount' => $limit,
        'rollover_enabled' => $rollover,
    ]);
}

function budgetMgrLineOf(BudgetPlan $plan, Category $category): Budget
{
    return $plan->lines()->where('category_id', $category->id)->firstOrFail();
}

function budgetMgrSpend(User $user, Category $category, int $cents, string $date, TransactionDirection $direction = TransactionDirection::Debit): void
{
    Transaction::factory()->for($user)->for(Account::factory()->for($user))->create([
        'category_id' => $category->id,
        'amount' => $cents,
        'direction' => $direction,
        'post_date' => $date,
    ]);
}

it('copies the previous initialised month without rollover', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01', total: 200000, expectedIncome: 300000);
    budgetMgrLine($january, $groceries, 40000);
    $february = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-10'));

    $copied = $this->manager->copyFrom($this->user, $february);
    $line = budgetMgrLineOf($copied, $groceries);

    expect($copied->initialised_at)->not->toBeNull()
        ->and($copied->total_amount)->toBe(200000)
        ->and($copied->expected_income)->toBe(300000)
        ->and($line->limit_amount)->toBe(40000)
        ->and($line->rolled_over_amount)->toBe(0);
});

it('refuses to copy into an initialised month', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01', total: 200000, expectedIncome: 300000);
    budgetMgrLine($january, $groceries, 40000);
    $february = budgetMgrPlan($this->user, '2026-02', total: 50000, expectedIncome: 60000);
    $februaryLine = budgetMgrLine($february, $groceries, 5000);

    expect(fn () => $this->manager->copyFrom($this->user, $february))->toThrow(BudgetPlanRefusedException::class);

    expect($february->fresh()->total_amount)->toBe(50000)
        ->and($february->fresh()->expected_income)->toBe(60000)
        ->and($februaryLine->fresh()->limit_amount)->toBe(5000);
});

it('moves the parent allocation with a child change', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 60000);
    $groceriesLine = budgetMgrLine($plan, $groceries, 10000);

    $this->manager->setAllocation($this->user, $groceriesLine, 15000);

    expect($groceriesLine->fresh()->limit_amount)->toBe(15000)
        ->and($foodLine->fresh()->limit_amount)->toBe(65000);
});

it('moves allocation between categories and refuses more than the source holds', function () {
    $dining = budgetMgrCategory($this->user, 'Dining');
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $diningLine = budgetMgrLine($plan, $dining, 20000);
    $groceriesLine = budgetMgrLine($plan, $groceries, 40000);

    $this->manager->moveMoney($this->user, $diningLine, $groceriesLine, 5000);

    expect($diningLine->fresh()->limit_amount)->toBe(15000)
        ->and($groceriesLine->fresh()->limit_amount)->toBe(45000)
        ->and($plan->fresh()->total_amount)->toBe(100000)
        ->and(app(BudgetPlanCalculator::class)->summarise($plan)->allocated)->toBe(60000);

    expect(fn () => $this->manager->moveMoney($this->user, $diningLine, $groceriesLine, 25000))
        ->toThrow(BudgetPlanRefusedException::class);

    expect($diningLine->fresh()->limit_amount)->toBe(15000)
        ->and($groceriesLine->fresh()->limit_amount)->toBe(45000);
});

it('refuses to move money between a parent and its direct child', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 60000);
    $groceriesLine = budgetMgrLine($plan, $groceries, 10000);

    expect(fn () => $this->manager->moveMoney($this->user, $foodLine, $groceriesLine, 1000))
        ->toThrow(BudgetPlanRefusedException::class)
        ->and(fn () => $this->manager->moveMoney($this->user, $groceriesLine, $foodLine, 1000))
        ->toThrow(BudgetPlanRefusedException::class);

    expect($foodLine->fresh()->limit_amount)->toBe(60000)
        ->and($groceriesLine->fresh()->limit_amount)->toBe(10000);
});

it('refuses to move money into another user budget line', function () {
    $dining = budgetMgrCategory($this->user, 'Dining');
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $own = budgetMgrLine($plan, $dining, 20000);

    $other = User::factory()->create();
    $otherPlan = budgetMgrPlan($other, '2026-03', total: 100000);
    $foreign = budgetMgrLine($otherPlan, budgetMgrCategory($other, 'Dining'), 30000);

    expect(fn () => $this->manager->moveMoney($this->user, $own, $foreign, 5000))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->manager->moveMoney($this->user, $foreign, $own, 5000))
        ->toThrow(ModelNotFoundException::class);

    expect($own->fresh()->limit_amount)->toBe(20000)
        ->and($foreign->fresh()->limit_amount)->toBe(30000);
});

it('propagates the rollover choice to later months', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01');
    $march = budgetMgrPlan($this->user, '2026-03');
    $januaryLine = budgetMgrLine($january, $groceries, 40000, rollover: true);
    $marchLine = budgetMgrLine($march, $groceries, 40000, rollover: true);

    $this->manager->setRollover($this->user, $januaryLine, false);
    $february = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));

    expect($januaryLine->fresh()->rollover_enabled)->toBeFalse()
        ->and($marchLine->fresh()->rollover_enabled)->toBeFalse()
        ->and(budgetMgrLineOf($february, $groceries)->rollover_enabled)->toBeFalse();
});

it('lets a new month inherit rollover that is on', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $dining = budgetMgrCategory($this->user, 'Dining');
    $january = budgetMgrPlan($this->user, '2026-01');
    budgetMgrLine($january, $groceries, 40000, rollover: true);
    budgetMgrLine($january, $dining, 10000, rollover: false);

    $february = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-05'));

    expect(budgetMgrLineOf($february, $groceries)->rollover_enabled)->toBeTrue()
        ->and(budgetMgrLineOf($february, $dining)->rollover_enabled)->toBeFalse();
});

it('refreshes carried amounts when rollover is switched', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01');
    $february = budgetMgrPlan($this->user, '2026-02');
    $januaryLine = budgetMgrLine($january, $groceries, 40000, rollover: false);
    $februaryLine = budgetMgrLine($february, $groceries, 40000, rollover: false);
    budgetMgrSpend($this->user, $groceries, 30000, '2026-01-10');

    $this->manager->setRollover($this->user, $januaryLine, true);

    expect($februaryLine->fresh()->rolled_over_amount)->toBe(10000);

    $this->manager->setRollover($this->user, $februaryLine, false);

    expect($februaryLine->fresh()->rolled_over_amount)->toBe(0)
        ->and($januaryLine->fresh()->rollover_enabled)->toBeTrue();
});

it('keeps a rollover change from touching earlier months or other users', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01');
    $february = budgetMgrPlan($this->user, '2026-02');
    $januaryLine = budgetMgrLine($january, $groceries, 40000, rollover: true);
    $februaryLine = budgetMgrLine($february, $groceries, 40000, rollover: true);
    $other = User::factory()->create();
    $otherLine = budgetMgrLine(budgetMgrPlan($other, '2026-02'), budgetMgrCategory($other, 'Groceries'), 1000, rollover: true);

    $this->manager->setRollover($this->user, $februaryLine, false);

    expect($januaryLine->fresh()->rollover_enabled)->toBeTrue()
        ->and($otherLine->fresh()->rollover_enabled)->toBeTrue();

    expect(fn () => $this->manager->setRollover($this->user, $otherLine, false))->toThrow(ModelNotFoundException::class);
});

it('opens an uninitialised plan with a zero line for each visible category', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $dining = budgetMgrCategory($this->user, 'Dining');
    Category::factory()->for($this->user)->create(['name' => 'Old', 'is_hidden' => true]);
    budgetMgrCategory(User::factory()->create(), 'Someone else');

    $plan = $this->manager->open($this->user, CarbonImmutable::parse('2026-04-17'));

    expect($plan->initialised_at)->toBeNull()
        ->and($plan->period_start->toDateString())->toBe('2026-04-01')
        ->and($plan->period_end->toDateString())->toBe('2026-04-30')
        ->and($plan->total_amount)->toBe(0)
        ->and($plan->lines()->pluck('category_id')->sort()->values()->all())->toBe(collect([$groceries->id, $dining->id])->sort()->values()->all())
        ->and($plan->lines()->pluck('limit_amount')->unique()->all())->toBe([0])
        ->and(budgetMgrLineOf($plan, $groceries)->name)->toBe('Groceries');
});

it('opens a month once and fills in categories added since', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $first = $this->manager->open($this->user, CarbonImmutable::parse('2026-04-01'));
    budgetMgrLineOf($first, $groceries)->update(['limit_amount' => 7000]);
    $dining = budgetMgrCategory($this->user, 'Dining');

    $second = $this->manager->open($this->user, CarbonImmutable::parse('2026-04-30'));

    expect($second->id)->toBe($first->id)
        ->and(BudgetPlan::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($second->lines()->count())->toBe(2)
        ->and(budgetMgrLineOf($second, $groceries)->limit_amount)->toBe(7000)
        ->and(budgetMgrLineOf($second, $dining)->limit_amount)->toBe(0);
});

it('initialises a plan and keeps the first initialised time on a later edit', function () {
    $plan = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));

    $initialised = $this->manager->initialise($this->user, $plan, 200000, 300000);
    $stamp = $initialised->initialised_at;

    $this->travelTo('2026-03-25 12:00:00');
    $edited = $this->manager->initialise($this->user, $plan, 250000, 0);

    expect($initialised->total_amount)->toBe(200000)
        ->and($initialised->expected_income)->toBe(300000)
        ->and($stamp)->not->toBeNull()
        ->and($edited->total_amount)->toBe(250000)
        ->and($edited->expected_income)->toBe(0)
        ->and($edited->initialised_at->equalTo($stamp))->toBeTrue();
});

it('refuses negative figures and another user plan when initialising', function () {
    $plan = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));
    $other = User::factory()->create();
    $otherPlan = $this->manager->open($other, CarbonImmutable::parse('2026-03-01'));

    expect(fn () => $this->manager->initialise($this->user, $plan, -1, 0))->toThrow(BudgetPlanRefusedException::class)
        ->and(fn () => $this->manager->initialise($this->user, $plan, 0, -1))->toThrow(BudgetPlanRefusedException::class)
        ->and(fn () => $this->manager->initialise($this->user, $otherPlan, 1000, 1000))->toThrow(ModelNotFoundException::class);

    expect($plan->fresh()->initialised_at)->toBeNull()
        ->and($otherPlan->fresh()->initialised_at)->toBeNull();
});

it('suggests the median monthly spend and income from history', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $salary = budgetMgrCategory($this->user, 'Salary');

    budgetMgrSpend($this->user, $groceries, 10000, '2026-02-10');
    budgetMgrSpend($this->user, $groceries, 30000, '2026-01-10');
    budgetMgrSpend($this->user, $groceries, 20000, '2025-12-10');
    budgetMgrSpend($this->user, $groceries, 99999, '2026-03-05');
    budgetMgrSpend($this->user, $salary, 400000, '2026-02-01', TransactionDirection::Credit);
    budgetMgrSpend($this->user, $salary, 500000, '2026-01-01', TransactionDirection::Credit);

    expect($this->manager->suggestFromHistory($this->user))->toBe(['spending' => 20000, 'income' => 450000]);
});

it('suggests zero when there is no history', function () {
    expect($this->manager->suggestFromHistory($this->user))->toBe(['spending' => 0, 'income' => 0]);
});

it('refuses to copy when no earlier initialised month exists', function () {
    budgetMgrPlan($this->user, '2026-01', initialised: false);
    budgetMgrPlan($this->user, '2026-04');
    $february = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));

    expect(fn () => $this->manager->copyFrom($this->user, $february))->toThrow(BudgetPlanRefusedException::class);

    expect($february->fresh()->initialised_at)->toBeNull();
});

it('skips categories the target lacks and leaves new categories at zero when copying', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $retired = budgetMgrCategory($this->user, 'Retired');
    $january = budgetMgrPlan($this->user, '2026-01', total: 100000);
    budgetMgrLine($january, $groceries, 40000);
    budgetMgrLine($january, $retired, 9000);
    $retired->update(['is_hidden' => true]);
    $fresh = budgetMgrCategory($this->user, 'Fresh');
    $february = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));

    $copied = $this->manager->copyFrom($this->user, $february);

    expect(budgetMgrLineOf($copied, $groceries)->limit_amount)->toBe(40000)
        ->and(budgetMgrLineOf($copied, $fresh)->limit_amount)->toBe(0)
        ->and($copied->lines()->where('category_id', $retired->id)->exists())->toBeFalse();
});

it('recomputes rolled over amounts instead of copying them', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01', total: 100000);
    budgetMgrLine($january, $groceries, 40000, rollover: true)->update(['rolled_over_amount' => 7000]);
    budgetMgrSpend($this->user, $groceries, 30000, '2026-01-10');
    $february = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));

    $copied = $this->manager->copyFrom($this->user, $february);

    expect(budgetMgrLineOf($copied, $groceries)->rolled_over_amount)->toBe(17000);
});

it('copies from the latest earlier month only and ignores another user', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01', total: 100000);
    $february = budgetMgrPlan($this->user, '2026-02', total: 120000);
    budgetMgrLine($january, $groceries, 1000);
    budgetMgrLine($february, $groceries, 2000);
    budgetMgrPlan($this->user, '2026-04', total: 999999);
    $other = User::factory()->create();
    $otherPlan = budgetMgrPlan($other, '2026-02', total: 888888);
    $march = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));

    $copied = $this->manager->copyFrom($this->user, $march);

    expect($copied->total_amount)->toBe(120000)
        ->and(budgetMgrLineOf($copied, $groceries)->limit_amount)->toBe(2000);

    expect(fn () => $this->manager->copyFrom($this->user, budgetMgrPlan($other, '2026-05', initialised: false)))
        ->toThrow(ModelNotFoundException::class)
        ->and($otherPlan->fresh()->total_amount)->toBe(888888);
});

it('makes a subcategory shared and takes its allocation out of the parent', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 60000);
    $groceriesLine = budgetMgrLine($plan, $groceries, 10000);

    $this->manager->setAllocation($this->user, $groceriesLine, null);

    expect($groceriesLine->fresh()->limit_amount)->toBeNull()
        ->and($foodLine->fresh()->limit_amount)->toBe(50000);

    $this->manager->setAllocation($this->user, $groceriesLine, 4000);

    expect($groceriesLine->fresh()->limit_amount)->toBe(4000)
        ->and($foodLine->fresh()->limit_amount)->toBe(54000);
});

it('refuses to share a top level category or set a negative allocation', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 60000);

    expect(fn () => $this->manager->setAllocation($this->user, $foodLine, null))->toThrow(BudgetPlanRefusedException::class)
        ->and(fn () => $this->manager->setAllocation($this->user, $foodLine, -1))->toThrow(BudgetPlanRefusedException::class);

    expect($foodLine->fresh()->limit_amount)->toBe(60000);
});

it('carries an allocation change up through every ancestor', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $dining = budgetMgrCategory($this->user, 'Dining', $food);
    $cafes = budgetMgrCategory($this->user, 'Cafes', $dining);
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 60000);
    $diningLine = budgetMgrLine($plan, $dining, 20000);
    $cafesLine = budgetMgrLine($plan, $cafes, 5000);

    $this->manager->setAllocation($this->user, $cafesLine, 8000);

    expect($cafesLine->fresh()->limit_amount)->toBe(8000)
        ->and($diningLine->fresh()->limit_amount)->toBe(23000)
        ->and($foodLine->fresh()->limit_amount)->toBe(63000);
});

it('refuses to edit allocations of another user line', function () {
    $other = User::factory()->create();
    $foreign = budgetMgrLine(budgetMgrPlan($other, '2026-03'), budgetMgrCategory($other, 'Dining'), 30000);

    expect(fn () => $this->manager->setAllocation($this->user, $foreign, 1))->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->limit_amount)->toBe(30000);
});

it('refreshes carried amounts when an allocation changes', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01');
    $february = budgetMgrPlan($this->user, '2026-02');
    $januaryLine = budgetMgrLine($january, $groceries, 40000, rollover: true);
    $februaryLine = budgetMgrLine($february, $groceries, 40000, rollover: true);
    budgetMgrSpend($this->user, $groceries, 30000, '2026-01-10');

    $this->manager->setAllocation($this->user, $januaryLine, 50000);

    expect($februaryLine->fresh()->rolled_over_amount)->toBe(20000);
});

it('refuses moves that are zero, negative, uncategorized, within one category or across plans', function () {
    $dining = budgetMgrCategory($this->user, 'Dining');
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $otherMonth = budgetMgrPlan($this->user, '2026-02', total: 100000);
    $diningLine = budgetMgrLine($plan, $dining, 20000);
    $groceriesLine = budgetMgrLine($plan, $groceries, 40000);
    $febGroceries = budgetMgrLine($otherMonth, $groceries, 40000);
    $uncategorised = Budget::factory()->inPlan($plan)->create(['category_id' => null, 'name' => 'Uncategorized', 'limit_amount' => 5000]);

    foreach ([
        [$diningLine, $groceriesLine, 0],
        [$diningLine, $groceriesLine, -100],
        [$diningLine, $diningLine, 100],
        [$diningLine, $febGroceries, 100],
        [$diningLine, $uncategorised, 100],
        [$uncategorised, $diningLine, 100],
    ] as [$from, $to, $amount]) {
        expect(fn () => $this->manager->moveMoney($this->user, $from, $to, $amount))->toThrow(BudgetPlanRefusedException::class);
    }

    expect($diningLine->fresh()->limit_amount)->toBe(20000)
        ->and($groceriesLine->fresh()->limit_amount)->toBe(40000)
        ->and($febGroceries->fresh()->limit_amount)->toBe(40000)
        ->and($uncategorised->fresh()->limit_amount)->toBe(5000);
});

it('lets a parent send only its own reserve and keeps its parent in step', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $transport = budgetMgrCategory($this->user, 'Transport');
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 60000);
    $groceriesLine = budgetMgrLine($plan, $groceries, 10000);
    $transportLine = budgetMgrLine($plan, $transport, 20000);

    expect(fn () => $this->manager->moveMoney($this->user, $foodLine, $transportLine, 50001))->toThrow(BudgetPlanRefusedException::class);

    $this->manager->moveMoney($this->user, $foodLine, $transportLine, 50000);

    expect($foodLine->fresh()->limit_amount)->toBe(10000)
        ->and($groceriesLine->fresh()->limit_amount)->toBe(10000)
        ->and($transportLine->fresh()->limit_amount)->toBe(70000);

    $this->manager->moveMoney($this->user, $groceriesLine, $transportLine, 4000);

    expect($groceriesLine->fresh()->limit_amount)->toBe(6000)
        ->and($foodLine->fresh()->limit_amount)->toBe(6000)
        ->and(app(BudgetPlanCalculator::class)->summarise($plan)->allocated)->toBe(6000 + 74000);
});

it('refuses to send from a shared line', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $transport = budgetMgrCategory($this->user, 'Transport');
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    budgetMgrLine($plan, $food, 60000);
    $sharedLine = budgetMgrLine($plan, $groceries, null);
    $transportLine = budgetMgrLine($plan, $transport, 20000);

    expect(fn () => $this->manager->moveMoney($this->user, $sharedLine, $transportLine, 1))->toThrow(BudgetPlanRefusedException::class);
});

it('starts a parent line added later at what its subcategories hold', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food', 'is_hidden' => true]);
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $plan = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));
    $this->manager->setAllocation($this->user, budgetMgrLineOf($plan, $groceries), 10000);

    expect($plan->lines()->where('category_id', $food->id)->exists())->toBeFalse();

    $food->update(['is_hidden' => false]);
    $reopened = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));

    expect(budgetMgrLineOf($reopened, $food)->limit_amount)->toBe(10000)
        ->and(app(BudgetPlanCalculator::class)->summarise($reopened)->allocated)->toBe(10000);
});

it('starts parents and children added together from the lowest level up', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food', 'is_hidden' => true]);
    $dining = Category::factory()->for($this->user)->create(['name' => 'Dining', 'parent_id' => $food->id, 'is_hidden' => true]);
    $cafes = budgetMgrCategory($this->user, 'Cafes', $dining);
    $plan = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));
    $this->manager->setAllocation($this->user, budgetMgrLineOf($plan, $cafes), 6000);

    $food->update(['is_hidden' => false]);
    $dining->update(['is_hidden' => false]);
    $reopened = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));

    expect(budgetMgrLineOf($reopened, $dining)->limit_amount)->toBe(6000)
        ->and(budgetMgrLineOf($reopened, $food)->limit_amount)->toBe(6000);
});

it('refuses to set a parent below what its subcategories hold', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 60000);
    $groceriesLine = budgetMgrLine($plan, $groceries, 10000);

    expect(fn () => $this->manager->setAllocation($this->user, $foodLine, 9999))->toThrow(BudgetPlanRefusedException::class);

    $this->manager->setAllocation($this->user, $foodLine, 10000);

    expect($foodLine->fresh()->limit_amount)->toBe(10000)
        ->and($groceriesLine->fresh()->limit_amount)->toBe(10000);
});

it('recomputes carried amounts when reopening adds lines to an initialised month', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $dining = Category::factory()->for($this->user)->create(['name' => 'Dining', 'is_hidden' => true]);
    $january = budgetMgrPlan($this->user, '2026-01');
    $february = budgetMgrPlan($this->user, '2026-02');
    budgetMgrLine($january, $groceries, 40000, rollover: true);
    budgetMgrLine($january, $dining, 20000, rollover: true);
    budgetMgrLine($february, $groceries, 40000, rollover: true);

    $dining->update(['is_hidden' => false]);
    $reopened = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));

    $line = budgetMgrLineOf($reopened, $dining);

    expect($line->rollover_enabled)->toBeTrue()
        ->and($line->rolled_over_amount)->toBe(20000);
});

it('keeps child allocations counted when copying into a month with a new parent line', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food', 'is_hidden' => true]);
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $january = budgetMgrPlan($this->user, '2026-01', total: 100000);
    budgetMgrLine($january, $groceries, 10000);
    $food->update(['is_hidden' => false]);
    $february = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));

    $copied = $this->manager->copyFrom($this->user, $february);

    expect(budgetMgrLineOf($copied, $food)->limit_amount)->toBe(10000)
        ->and(app(BudgetPlanCalculator::class)->summarise($copied)->allocated)->toBe(10000);
});

it('refuses to share a subcategory whose parent has no budget line', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food', 'is_hidden' => true]);
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $plan = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));
    $line = budgetMgrLineOf($plan, $groceries);

    expect(fn () => $this->manager->setAllocation($this->user, $line, null))->toThrow(BudgetPlanRefusedException::class);
});

it('keeps a fully refunded month in the suggested median spending', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');

    budgetMgrSpend($this->user, $groceries, 10000, '2026-01-10');
    budgetMgrSpend($this->user, $groceries, 2000, '2026-02-10');
    budgetMgrSpend($this->user, $groceries, 2000, '2026-02-12', TransactionDirection::Credit);

    expect($this->manager->suggestFromHistory($this->user)['spending'])->toBe(5000);
});

it('refuses to copy a Shared line whose target has no parent line to draw on', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $groceries = budgetMgrCategory($this->user, 'Groceries', $food);
    $january = budgetMgrPlan($this->user, '2026-01', total: 100000);
    budgetMgrLine($january, $food, 60000);
    budgetMgrLine($january, $groceries, null);
    $food->update(['is_hidden' => true]);
    $february = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));

    expect(fn () => $this->manager->copyFrom($this->user, $february))->toThrow(BudgetPlanRefusedException::class);

    expect($february->fresh()->initialised_at)->toBeNull()
        ->and($february->fresh()->total_amount)->toBe(0)
        ->and(budgetMgrLineOf($february, $groceries)->limit_amount)->toBe(0);
});

it('resets draft allocations in the target month before copying', function () {
    $groceries = budgetMgrCategory($this->user, 'Groceries');
    $january = budgetMgrPlan($this->user, '2026-01', total: 100000);
    budgetMgrLine($january, $groceries, 40000);
    $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));
    $fresh = budgetMgrCategory($this->user, 'Fresh');
    $draft = $this->manager->open($this->user, CarbonImmutable::parse('2026-02-01'));
    budgetMgrLineOf($draft, $fresh)->update(['limit_amount' => 5000]);

    $copied = $this->manager->copyFrom($this->user, $draft);

    expect(budgetMgrLineOf($copied, $fresh)->limit_amount)->toBe(0)
        ->and(budgetMgrLineOf($copied, $groceries)->limit_amount)->toBe(40000);
});

it('updates existing ancestors when intermediate categories are unhidden in phases', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food', 'is_hidden' => true]);
    $dining = Category::factory()->for($this->user)->create(['name' => 'Dining', 'parent_id' => $food->id, 'is_hidden' => true]);
    $cafes = budgetMgrCategory($this->user, 'Cafes', $dining);
    $plan = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));
    $this->manager->setAllocation($this->user, budgetMgrLineOf($plan, $cafes), 6000);

    $food->update(['is_hidden' => false]);
    $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));
    $dining->update(['is_hidden' => false]);
    $reopened = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));

    expect(budgetMgrLineOf($reopened, $food)->limit_amount)->toBe(6000)
        ->and(budgetMgrLineOf($reopened, $dining)->limit_amount)->toBe(6000)
        ->and(app(BudgetPlanCalculator::class)->summarise($reopened)->allocated)->toBe(6000);
});

it('raises an existing ancestor that is below what a newly added line holds', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $dining = Category::factory()->for($this->user)->create(['name' => 'Dining', 'parent_id' => $food->id, 'is_hidden' => true]);
    $cafes = budgetMgrCategory($this->user, 'Cafes', $dining);
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 0);
    budgetMgrLine($plan, $cafes, 6000);

    $dining->update(['is_hidden' => false]);
    $reopened = $this->manager->open($this->user, CarbonImmutable::parse('2026-03-01'));

    expect($foodLine->fresh()->limit_amount)->toBe(6000)
        ->and(budgetMgrLineOf($reopened, $dining)->limit_amount)->toBe(6000);
});

it('counts dedicated allocations below a Shared intermediate when sizing the parent reserve', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $dining = budgetMgrCategory($this->user, 'Dining', $food);
    $cafes = budgetMgrCategory($this->user, 'Cafes', $dining);
    $transport = budgetMgrCategory($this->user, 'Transport');
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 6000);
    budgetMgrLine($plan, $dining, null);
    $cafesLine = budgetMgrLine($plan, $cafes, 0);
    $transportLine = budgetMgrLine($plan, $transport, 0);

    $this->manager->setAllocation($this->user, $cafesLine, 5000);

    expect($foodLine->fresh()->limit_amount)->toBe(11000);

    expect(fn () => $this->manager->moveMoney($this->user, $foodLine, $transportLine, 6001))->toThrow(BudgetPlanRefusedException::class)
        ->and(fn () => $this->manager->setAllocation($this->user, $foodLine, 4999))->toThrow(BudgetPlanRefusedException::class);

    $this->manager->moveMoney($this->user, $foodLine, $transportLine, 6000);

    expect($foodLine->fresh()->limit_amount)->toBe(5000)
        ->and($cafesLine->fresh()->limit_amount)->toBe(5000)
        ->and(app(BudgetPlanCalculator::class)->summarise($plan)->allocated)->toBe(5000 + 6000);
});

it('keeps ancestors in step when an intermediate line switches to or from Shared', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $dining = budgetMgrCategory($this->user, 'Dining', $food);
    $cafes = budgetMgrCategory($this->user, 'Cafes', $dining);
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 9000);
    $diningLine = budgetMgrLine($plan, $dining, 7000);
    budgetMgrLine($plan, $cafes, 5000);

    $this->manager->setAllocation($this->user, $diningLine, null);

    expect($diningLine->fresh()->limit_amount)->toBeNull()
        ->and($foodLine->fresh()->limit_amount)->toBe(7000);

    $this->manager->setAllocation($this->user, $diningLine, 8000);

    expect($diningLine->fresh()->limit_amount)->toBe(8000)
        ->and($foodLine->fresh()->limit_amount)->toBe(10000);
});

it('adds a move into a Shared line to what is already dedicated below it', function () {
    $food = budgetMgrCategory($this->user, 'Food');
    $dining = budgetMgrCategory($this->user, 'Dining', $food);
    $cafes = budgetMgrCategory($this->user, 'Cafes', $dining);
    $transport = budgetMgrCategory($this->user, 'Transport');
    $plan = budgetMgrPlan($this->user, '2026-03', total: 100000);
    $foodLine = budgetMgrLine($plan, $food, 6000);
    $diningLine = budgetMgrLine($plan, $dining, null);
    $cafesLine = budgetMgrLine($plan, $cafes, 5000);
    $transportLine = budgetMgrLine($plan, $transport, 10000);

    $this->manager->moveMoney($this->user, $transportLine, $diningLine, 1000);

    expect($diningLine->fresh()->limit_amount)->toBe(6000)
        ->and($cafesLine->fresh()->limit_amount)->toBe(5000)
        ->and($foodLine->fresh()->limit_amount)->toBe(7000)
        ->and($transportLine->fresh()->limit_amount)->toBe(9000)
        ->and(app(BudgetPlanCalculator::class)->summarise($plan)->allocated)->toBe(7000 + 9000);
});
