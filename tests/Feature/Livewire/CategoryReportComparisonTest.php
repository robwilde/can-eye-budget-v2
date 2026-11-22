<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Livewire\CategoryReport;
use App\Models\Account;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
});

function reportSpend(object $test, int $cents, string $date, ?Category $category = null, array $overrides = []): Transaction
{
    return Transaction::factory()->for($test->user)->debit()->create([
        'account_id' => $test->account->id,
        'category_id' => ($category ?? $test->groceries)->id,
        'amount' => $cents,
        'post_date' => $date,
        ...$overrides,
    ]);
}

function reportEarn(object $test, int $cents, string $date, ?Category $category = null, array $overrides = []): Transaction
{
    return Transaction::factory()->for($test->user)->credit()->create([
        'account_id' => $test->account->id,
        'category_id' => $category?->id,
        'amount' => $cents,
        'post_date' => $date,
        ...$overrides,
    ]);
}

function reportFor(object $test, array $query = []): Testable
{
    return Livewire::actingAs($test->user)
        ->withQueryParams($query)
        ->test(CategoryReport::class);
}

const MARCH = ['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31'];

it('shows percent change against the equal-length prior period', function () {
    reportSpend($this, 200000, '2026-03-10');
    reportSpend($this, 100000, '2026-02-28');
    reportSpend($this, 60000, '2026-01-29');
    reportSpend($this, 90000, '2026-01-28');

    $summary = reportFor($this, MARCH)->instance()->summary();

    expect($summary['out'])->toBe(200000)
        ->and($summary['outChange'])->toBe(25);
});

it('has no change when the prior period had none or the mode is plan', function () {
    reportSpend($this, 200000, '2026-03-10');

    expect(reportFor($this, MARCH)->instance()->summary())->toMatchArray(['outChange' => null, 'inChange' => null, 'netChange' => null]);

    reportSpend($this, 160000, '2026-02-10');

    expect(reportFor($this, [...MARCH, 'mode' => 'plan'])->instance()->summary()['outChange'])->toBeNull();
});

it('leaves pending rows and excluded accounts out of the prior period', function () {
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();

    reportSpend($this, 200000, '2026-03-10');
    reportSpend($this, 160000, '2026-02-10');
    reportSpend($this, 500000, '2026-02-11', overrides: ['status' => TransactionStatus::Pending]);
    reportSpend($this, 500000, '2026-02-12', overrides: ['account_id' => $excluded->id]);

    expect(reportFor($this, MARCH)->instance()->summary()['outChange'])->toBe(25);
});

it('swaps a backwards range and alerts', function () {
    reportSpend($this, 3000, '2026-03-15');
    reportSpend($this, 9000, '2026-04-02');

    $report = reportFor($this, ['period' => 'custom', 'from' => '2026-03-31', 'to' => '2026-03-01']);

    $report->assertSet('from', '2026-03-01')
        ->assertSet('to', '2026-03-31')
        ->assertSet('rangeSwapped', true)
        ->assertSeeHtml('data-testid="range-swapped"');

    expect($report->instance()->summary()['out'])->toBe(3000);
});

it('shows no alert for a forward range', function () {
    reportFor($this, MARCH)
        ->assertSet('rangeSwapped', false)
        ->assertDontSeeHtml('data-testid="range-swapped"');
});

it('shows the budget card only for the current month', function () {
    reportSpend($this, 25000, '2026-03-10');
    reportSpend($this, 9000, '2026-02-10');
    BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'total_amount' => 100000, 'initialised_at' => now()]);
    BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'total_amount' => 100000, 'initialised_at' => now()]);

    $report = reportFor($this);

    expect($report->instance()->budgetPerformance())->toBe(['spent' => 25000, 'budget' => 100000, 'percent' => 25]);

    $report->assertSeeHtml('data-testid="budget-performance"')
        ->call('step', -1)
        ->assertSet('from', '2026-02-01')
        ->assertDontSeeHtml('data-testid="budget-performance"');

    expect($report->instance()->budgetPerformance())->toBeNull();
});

it('shows no budget card without an initialised plan, in plan mode or for a past range', function () {
    BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'total_amount' => 100000, 'initialised_at' => null]);

    expect(reportFor($this)->instance()->budgetPerformance())->toBeNull();

    BudgetPlan::query()->update(['initialised_at' => now()]);

    expect(reportFor($this)->instance()->budgetPerformance())->not->toBeNull()
        ->and(reportFor($this, ['mode' => 'plan'])->instance()->budgetPerformance())->toBeNull()
        ->and(reportFor($this, ['period' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-28'])->instance()->budgetPerformance())->toBeNull();
});

it('nets refunds against expenses', function () {
    reportSpend($this, 6000, '2026-03-05');
    reportEarn($this, 1000, '2026-03-06', $this->groceries);

    $report = reportFor($this, MARCH);
    $summary = $report->instance()->summary();
    $row = collect($report->instance()->expenseRows())->sole();

    expect($summary['out'])->toBe(5000)
        ->and($summary['in'])->toBe(0)
        ->and($row['name'])->toBe('Groceries')
        ->and($row['total'])->toBe(5000)
        ->and($row['count'])->toBe(2)
        ->and($report->instance()->incomeRows())->toBe([]);
});

it('moves a category refunded beyond its spending to income', function () {
    reportSpend($this, 3000, '2026-03-05');
    reportEarn($this, 10000, '2026-03-06', $this->groceries);

    $report = reportFor($this, MARCH);

    expect($report->instance()->summary())->toMatchArray(['out' => 0, 'in' => 7000])
        ->and($report->instance()->expenseRows())->toBe([])
        ->and(collect($report->instance()->incomeRows())->sole()['total'])->toBe(7000);
});

it('nets a refund in a later month against the whole period but each month in the chart', function () {
    reportSpend($this, 6000, '2026-02-27');
    reportEarn($this, 1000, '2026-03-02', $this->groceries);

    $report = reportFor($this, ['period' => 'custom', 'from' => '2026-02-01', 'to' => '2026-03-31']);
    $chart = $report->instance()->chart();

    expect($report->instance()->summary())->toMatchArray(['out' => 5000, 'in' => 0])
        ->and($chart['expense'])->toBe([6000, 0])
        ->and($chart['income'])->toBe([0, 1000]);
});

it('does not net uncategorised income against uncategorised spend', function () {
    Transaction::factory()->for($this->user)->debit()->create(['account_id' => $this->account->id, 'category_id' => null, 'amount' => 2000, 'post_date' => '2026-03-05']);
    reportEarn($this, 5000, '2026-03-06');

    expect(reportFor($this, MARCH)->instance()->summary())->toMatchArray(['out' => 2000, 'in' => 5000]);
});

it('filters the report by tag', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $other = Tag::factory()->for($this->user)->create();
    reportSpend($this, 4000, '2026-03-05')->tags()->attach($tag);
    reportSpend($this, 1000, '2026-03-06')->tags()->attach($other);
    reportSpend($this, 700, '2026-03-07');

    expect(reportFor($this, [...MARCH, 'tag' => (string) $tag->id])->instance()->summary()['out'])->toBe(4000)
        ->and(reportFor($this, MARCH)->instance()->summary()['out'])->toBe(5700);
});

it('compares a filtered report against the same filter in the prior period', function () {
    $tag = Tag::factory()->for($this->user)->create();
    reportSpend($this, 200000, '2026-03-10')->tags()->attach($tag);
    reportSpend($this, 160000, '2026-02-10')->tags()->attach($tag);
    reportSpend($this, 500000, '2026-02-11');

    expect(reportFor($this, [...MARCH, 'tag' => (string) $tag->id])->instance()->summary()['outChange'])->toBe(25);
});

it('filters the report by account', function () {
    $second = Account::factory()->for($this->user)->create();
    reportSpend($this, 4000, '2026-03-05');
    reportSpend($this, 1000, '2026-03-06', overrides: ['account_id' => $second->id]);

    expect(reportFor($this, [...MARCH, 'account' => (string) $second->id])->instance()->summary()['out'])->toBe(1000);
});

it('ignores other users\' accounts and tags in report filters', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create(['name' => 'Stranger Savings']);
    $strangerTag = Tag::factory()->for($stranger)->create(['name' => 'Stranger Holiday']);
    $foreign = Transaction::factory()->for($stranger)->debit()->create(['account_id' => $strangerAccount->id, 'amount' => 99000, 'post_date' => '2026-03-05']);
    $foreign->tags()->attach($strangerTag);
    reportSpend($this, 4000, '2026-03-05');

    $report = reportFor($this, [...MARCH, 'account' => (string) $strangerAccount->id, 'tag' => (string) $strangerTag->id]);

    $report->assertDontSee('Stranger Savings')->assertDontSee('Stranger Holiday');

    expect($report->instance()->summary()['out'])->toBe(4000);
});

it('filters the report by an absolute amount range', function () {
    reportSpend($this, 500, '2026-03-05');
    reportSpend($this, 4000, '2026-03-06');
    reportSpend($this, 20000, '2026-03-07', overrides: ['amount' => -20000]);

    expect(reportFor($this, [...MARCH, 'min' => '10', 'max' => '300'])->instance()->summary()['out'])->toBe(4000 + 20000)
        ->and(reportFor($this, [...MARCH, 'min' => '10', 'max' => '100'])->instance()->summary()['out'])->toBe(4000)
        ->and(reportFor($this, [...MARCH, 'min' => 'abc', 'max' => '-5'])->instance()->summary()['out'])->toBe(24500);
});

it('sorts the breakdown by amount or by transaction count', function () {
    $big = Category::factory()->for($this->user)->create(['name' => 'Rent']);
    reportSpend($this, 90000, '2026-03-02', $big);
    reportSpend($this, 1000, '2026-03-03');
    reportSpend($this, 1000, '2026-03-04');
    reportSpend($this, 1000, '2026-03-05');

    $byAmount = collect(reportFor($this, MARCH)->instance()->expenseRows())->pluck('name')->all();
    $byCount = collect(reportFor($this, [...MARCH, 'sort' => 'count'])->instance()->expenseRows())->pluck('name')->all();
    $invalid = collect(reportFor($this, [...MARCH, 'sort' => 'nonsense'])->instance()->expenseRows())->pluck('name')->all();

    expect($byAmount)->toBe(['Rent', 'Groceries'])
        ->and($byCount)->toBe(['Groceries', 'Rent'])
        ->and($invalid)->toBe(['Rent', 'Groceries']);
});

it('paginates the activity breakdown', function () {
    foreach (range(1, 12) as $i) {
        reportSpend($this, $i * 100, '2026-03-05', Category::factory()->for($this->user)->create(['name' => 'Cat '.$i]));
    }

    $report = reportFor($this, MARCH);
    $first = $report->instance()->expenseView();

    expect($first['rows'])->toHaveCount(10)
        ->and($first['pages'])->toBe(2)
        ->and($first['rows'][0]['name'])->toBe('Cat 12');

    $report->set('expensePage', 2);
    $second = $report->instance()->expenseView();

    expect($second['rows'])->toHaveCount(2)
        ->and($second['page'])->toBe(2)
        ->and($second['rows'][1]['name'])->toBe('Cat 1');

    $report->set('expensePage', 99);

    expect($report->instance()->expenseView()['page'])->toBe(2);

    $report->set('sort', 'count');

    expect($report->get('expensePage'))->toBe(1);
});

it('steps a month at a time and stops at the current period', function () {
    $report = reportFor($this);

    $report->call('step', 1)->assertSet('period', 'this-month');

    $report->call('step', -1)
        ->assertSet('period', 'custom')
        ->assertSet('from', '2026-02-01')
        ->assertSet('to', '2026-02-28');

    $report->call('step', -1)->assertSet('from', '2026-01-01')->assertSet('to', '2026-01-31');
    $report->call('step', 1)->call('step', 1)
        ->assertSet('from', '2026-03-01')
        ->assertSet('to', '2026-03-31');

    $report->call('step', 1)->assertSet('from', '2026-03-01');
});

it('steps a custom range by its own length', function () {
    $report = reportFor($this, ['period' => 'custom', 'from' => '2026-03-03', 'to' => '2026-03-09']);

    $report->call('step', -1)
        ->assertSet('from', '2026-02-24')
        ->assertSet('to', '2026-03-02');
});

it('steps a multi-month range by its own number of months', function () {
    $report = reportFor($this, ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-02-28']);

    $report->call('step', -1)
        ->assertSet('from', '2025-11-01')
        ->assertSet('to', '2025-12-31');

    $report->call('step', 1)
        ->assertSet('from', '2026-01-01')
        ->assertSet('to', '2026-02-28');
});

it('cannot step through all time', function () {
    reportFor($this, ['period' => 'all'])
        ->call('step', -1)
        ->assertSet('period', 'all');
});

it('keeps other users\' transactions out of the comparison and filters', function () {
    $stranger = User::factory()->create();
    Transaction::factory()->for($stranger)->debit()->create(['account_id' => Account::factory()->for($stranger)->create()->id, 'amount' => 800000, 'post_date' => '2026-02-10']);
    reportSpend($this, 100000, '2026-03-10');
    reportSpend($this, 100000, '2026-02-10');

    expect(reportFor($this, MARCH)->instance()->summary()['outChange'])->toBe(0);
});
