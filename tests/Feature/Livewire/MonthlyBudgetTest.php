<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\TransactionDirection;
use App\Livewire\MonthlyBudget;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-03-22 12:00:00');
    $this->user = User::factory()->create();
});

function monthlyBudgetPlan(User $user, string $month, bool $initialised = true, int $total = 100000, int $expectedIncome = 0): BudgetPlan
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

function monthlyBudgetLine(BudgetPlan $plan, string $name, ?int $limit, ?Category $parent = null, bool $rollover = false): Budget
{
    $category = Category::factory()->for($plan->user)->create(['name' => $name, 'parent_id' => $parent?->id]);

    return Budget::factory()->inPlan($plan)->withCategory($category)->create([
        'name' => $name,
        'limit_amount' => $limit,
        'rollover_enabled' => $rollover,
    ]);
}

function monthlyBudgetSpend(User $user, Budget $line, int $cents, string $date, TransactionDirection $direction = TransactionDirection::Debit): void
{
    Transaction::factory()->for($user)->for(Account::factory()->for($user))->create([
        'category_id' => $line->category_id,
        'amount' => $cents,
        'direction' => $direction,
        'post_date' => $date,
    ]);
}

function monthlyBudgetPage(User $user, array $query = []): Testable
{
    return Livewire::actingAs($user)->withQueryParams($query)->test(MonthlyBudget::class);
}

it('requires a signed-in user to view the budget page', function () {
    $this->get(route('budget'))->assertRedirect(route('login'));

    $this->actingAs($this->user)->get(route('budget'))
        ->assertOk()
        ->assertSeeLivewire(MonthlyBudget::class)
        ->assertSee('March 2026');
});

it('offers the budget page in the navigation', function () {
    $this->actingAs($this->user)->get(route('dashboard'))->assertSee(route('budget'), false);
});

it('refuses to edit another user budget plan from the budget page', function () {
    $mine = monthlyBudgetPlan($this->user, '2026-03');
    $other = User::factory()->create();
    $otherPlan = monthlyBudgetPlan($other, '2026-03');
    $foreign = monthlyBudgetLine($otherPlan, 'Groceries', 30000);
    $own = monthlyBudgetLine($mine, 'Dining', 10000);

    $component = monthlyBudgetPage($this->user);

    expect(fn () => $component->call('setAllocation', $foreign->id, '1.00'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $component->call('setRollover', $foreign->id, true))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $component
            ->set('moveFromId', (string) $own->id)
            ->set('moveToId', (string) $foreign->id)
            ->set('moveAmount', '1.00')
            ->call('moveMoney'))->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->limit_amount)->toBe(30000)
        ->and($foreign->fresh()->rollover_enabled)->toBeFalse()
        ->and($own->fresh()->limit_amount)->toBe(10000)
        ->and($otherPlan->fresh()->total_amount)->toBe(100000);
});

it('audits moving money on the budget page', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $dining = monthlyBudgetLine($plan, 'Dining', 20000);
    $groceries = monthlyBudgetLine($plan, 'Groceries', 40000);

    monthlyBudgetPage($this->user)
        ->set('moveFromId', (string) $dining->id)
        ->set('moveToId', (string) $groceries->id)
        ->set('moveAmount', '50.00')
        ->call('moveMoney')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.monthly-budget.moveMoney')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($dining->fresh()->limit_amount)->toBe(15000)
        ->and($groceries->fresh()->limit_amount)->toBe(45000);
});

it('shows the status filter only when a category is over budget', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $groceries = monthlyBudgetLine($plan, 'Groceries', 10000);
    monthlyBudgetLine($plan, 'Dining', 10000);

    monthlyBudgetSpend($this->user, $groceries, 4000, '2026-03-05');

    monthlyBudgetPage($this->user)->assertDontSeeHtml('data-testid="status-filter"');

    monthlyBudgetSpend($this->user, $groceries, 7000, '2026-03-06');

    monthlyBudgetPage($this->user)
        ->assertSeeHtml('data-testid="status-filter"')
        ->assertSee('On Track')
        ->assertSee('Over Budget');
});

it('narrows the category list with the status filter', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $groceries = monthlyBudgetLine($plan, 'Groceries', 10000);
    monthlyBudgetLine($plan, 'Dining', 10000);
    monthlyBudgetSpend($this->user, $groceries, 12000, '2026-03-05');

    $page = monthlyBudgetPage($this->user);
    $names = fn () => collect($page->instance()->rows)->map(fn (array $row): string => $row['line']->name)->sort()->values()->all();

    expect($names())->toBe(['Dining', 'Groceries']);

    $page->set('filter', 'over');
    expect($names())->toBe(['Groceries']);

    $page->set('filter', 'on_track');
    expect($names())->toBe(['Dining']);

    $page->set('filter', 'nonsense');
    expect($names())->toBe(['Dining', 'Groceries']);
});

it('hides month links outside the allowed range', function () {
    monthlyBudgetPage($this->user, ['month' => '2028-03'])
        ->assertSee('March 2028')
        ->assertDontSeeHtml('data-testid="next-month"')
        ->assertSeeHtml('data-testid="previous-month"');

    monthlyBudgetPage($this->user, ['month' => '2024-03'])
        ->assertSee('March 2024')
        ->assertDontSeeHtml('data-testid="previous-month"')
        ->assertSeeHtml('data-testid="next-month"');

    monthlyBudgetPage($this->user)
        ->assertSeeHtml('data-testid="previous-month"')
        ->assertSeeHtml('data-testid="next-month"');
});

it('clamps a month outside the range and ignores garbage', function () {
    monthlyBudgetPage($this->user, ['month' => '2035-01'])->assertSet('month', '2028-03');
    monthlyBudgetPage($this->user, ['month' => '2001-01'])->assertSet('month', '2024-03');
    monthlyBudgetPage($this->user, ['month' => 'not-a-month'])->assertSet('month', '2026-03');
    monthlyBudgetPage($this->user)->set('month', '2026-13')->assertSet('month', '2026-03');
});

it('reaches back to the oldest transaction when it is older than two years', function () {
    Transaction::factory()->for($this->user)->for(Account::factory()->for($this->user))->create(['post_date' => '2023-06-15']);

    monthlyBudgetPage($this->user, ['month' => '2023-06'])
        ->assertSee('June 2023')
        ->assertDontSeeHtml('data-testid="previous-month"');

    monthlyBudgetPage($this->user, ['month' => '2023-05'])->assertSet('month', '2023-06');
});

it('replaces the donut with a fix allocations warning when over-allocated', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03', total: 100000);
    monthlyBudgetLine($plan, 'Groceries', 70000);
    monthlyBudgetLine($plan, 'Dining', 50000);

    monthlyBudgetPage($this->user)
        ->assertDontSeeHtml('data-testid="budget-donut"')
        ->assertSeeHtml('data-testid="over-allocated-warning"')
        ->assertSeeHtml('data-testid="fix-allocations"')
        ->assertSee('Fix allocations');
});

it('shows the donut while allocations fit the total', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03', total: 100000);
    monthlyBudgetLine($plan, 'Groceries', 70000);

    monthlyBudgetPage($this->user)
        ->assertSeeHtml('data-testid="budget-donut"')
        ->assertDontSeeHtml('data-testid="over-allocated-warning"');
});

it('shows suggested daily spend only for the current month', function () {
    $current = monthlyBudgetPlan($this->user, '2026-03');
    $past = monthlyBudgetPlan($this->user, '2026-02');
    $currentLine = monthlyBudgetLine($current, 'Groceries', 30000);
    $pastLine = monthlyBudgetLine($past, 'Groceries', 30000);

    monthlyBudgetPage($this->user)
        ->set('drilldownId', $currentLine->id)
        ->assertSeeHtml('data-testid="suggested-daily"')
        ->assertSee('Suggested daily spend')
        ->assertSee('$30.00');

    monthlyBudgetPage($this->user, ['month' => '2026-02'])
        ->set('drilldownId', $pastLine->id)
        ->assertSeeHtml('data-testid="budget-drilldown"')
        ->assertDontSee('Suggested daily spend');
});

it('shows the drilldown figures for a category', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $line = monthlyBudgetLine($plan, 'Groceries', 30000);
    monthlyBudgetSpend($this->user, $line, 12345, '2026-03-05');
    monthlyBudgetSpend($this->user, $line, 1000, '2026-02-05');

    monthlyBudgetPage($this->user)
        ->set('drilldownId', $line->id)
        ->assertSee('Recent transactions')
        ->assertSee('$123.45')
        ->assertSee('Monthly average')
        ->assertSee('$10.00');
});

it('ignores a drilldown id outside the viewed plan', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    monthlyBudgetLine($plan, 'Groceries', 30000);
    $other = User::factory()->create();
    $foreign = monthlyBudgetLine(monthlyBudgetPlan($other, '2026-03'), 'Secret', 30000);

    monthlyBudgetPage($this->user)
        ->set('drilldownId', $foreign->id)
        ->assertDontSeeHtml('data-testid="budget-drilldown"')
        ->assertDontSee('Secret');
});

it('sets up an unopened month from budgeted spending and expected income', function () {
    Category::factory()->for($this->user)->create(['name' => 'Groceries']);

    monthlyBudgetPage($this->user)
        ->assertSeeHtml('data-testid="budget-setup"')
        ->set('totalInput', '2000.00')
        ->set('incomeInput', '3000')
        ->call('initialise')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-testid="budget-setup"')
        ->assertSeeHtml('data-testid="budget-categories"');

    $plan = BudgetPlan::query()->where('user_id', $this->user->id)->sole();

    expect($plan->total_amount)->toBe(200000)
        ->and($plan->expected_income)->toBe(300000)
        ->and($plan->initialised_at)->not->toBeNull()
        ->and($plan->period_start->toDateString())->toBe('2026-03-01')
        ->and($plan->lines()->count())->toBe(1);
});

it('counts blank expected income as zero and refuses bad figures', function () {
    $page = monthlyBudgetPage($this->user)->set('totalInput', '500')->call('initialise')->assertHasNoErrors();

    expect(BudgetPlan::query()->where('user_id', $this->user->id)->sole()->expected_income)->toBe(0);

    $other = User::factory()->create();

    monthlyBudgetPage($other)->set('totalInput', '-5')->call('initialise')->assertHasErrors('setup');
    monthlyBudgetPage($other)->set('totalInput', 'abc')->call('initialise')->assertHasErrors('setup');
    monthlyBudgetPage($other)->set('totalInput', '')->call('initialise')->assertHasErrors('setup');

    expect(BudgetPlan::query()->where('user_id', $other->id)->exists())->toBeFalse()
        ->and($page)->not->toBeNull();
});

it('offers to copy an earlier month or start fresh', function () {
    $january = monthlyBudgetPlan($this->user, '2026-01', total: 200000, expectedIncome: 300000);
    $groceries = monthlyBudgetLine($january, 'Groceries', 40000);

    $page = monthlyBudgetPage($this->user, ['month' => '2026-02'])
        ->assertSee('Copy from January 2026')
        ->assertSeeHtml('data-testid="start-fresh"');

    $page->set('startFresh', true)
        ->assertDontSeeHtml('data-testid="copy-previous"')
        ->assertSeeHtml('data-testid="total-input"');

    monthlyBudgetPage($this->user, ['month' => '2026-02'])->call('copyFromPrevious')->assertHasNoErrors();

    $february = BudgetPlan::query()->where('user_id', $this->user->id)->whereDate('period_start', '2026-02-01')->sole();

    expect($february->total_amount)->toBe(200000)
        ->and($february->expected_income)->toBe(300000)
        ->and($february->initialised_at)->not->toBeNull()
        ->and($february->lines()->where('category_id', $groceries->category_id)->sole()->limit_amount)->toBe(40000);
});

it('shows no copy offer when no earlier month is initialised', function () {
    monthlyBudgetPlan($this->user, '2026-01', initialised: false);

    monthlyBudgetPage($this->user, ['month' => '2026-02'])
        ->assertDontSeeHtml('data-testid="copy-previous"')
        ->assertSeeHtml('data-testid="total-input"');
});

it('suggests budgeted spending and expected income from history', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $account = Account::factory()->for($this->user)->create();

    foreach (['2026-02-10', '2026-01-10'] as $date) {
        Transaction::factory()->for($this->user)->for($account)->create(['category_id' => $groceries->id, 'amount' => 25000, 'direction' => TransactionDirection::Debit, 'post_date' => $date]);
    }

    monthlyBudgetPage($this->user)
        ->assertSeeHtml('data-testid="use-suggestion"')
        ->assertSee('$250.00 spending');
});

it('refuses to copy into a month that is already set up', function () {
    monthlyBudgetPlan($this->user, '2026-02');
    monthlyBudgetPlan($this->user, '2026-03');

    monthlyBudgetPage($this->user)->call('copyFromPrevious')->assertHasErrors('budget');
});

it('saves an allocation and keeps the parent in step', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $food = monthlyBudgetLine($plan, 'Food', 60000);
    $groceries = monthlyBudgetLine($plan, 'Groceries', 10000, Category::query()->find($food->category_id));

    monthlyBudgetPage($this->user)
        ->call('setAllocation', $groceries->id, '150.00')
        ->assertHasNoErrors();

    expect($groceries->fresh()->limit_amount)->toBe(15000)
        ->and($food->fresh()->limit_amount)->toBe(65000);
});

it('makes a subcategory shared when its allocation is cleared and zeroes a top level one', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $food = monthlyBudgetLine($plan, 'Food', 60000);
    $groceries = monthlyBudgetLine($plan, 'Groceries', 10000, Category::query()->find($food->category_id));

    $page = monthlyBudgetPage($this->user)->call('setAllocation', $groceries->id, '');

    expect($groceries->fresh()->limit_amount)->toBeNull()
        ->and($food->fresh()->limit_amount)->toBe(50000);

    $page->assertSee('Shared')->call('setAllocation', $food->id, '')->assertHasNoErrors();

    expect($food->fresh()->limit_amount)->toBe(0);
});

it('rejects an invalid allocation and a refused one without writing', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $food = monthlyBudgetLine($plan, 'Food', 60000);
    monthlyBudgetLine($plan, 'Groceries', 10000, Category::query()->find($food->category_id));

    monthlyBudgetPage($this->user)
        ->call('setAllocation', $food->id, 'abc')
        ->assertHasErrors('budget')
        ->call('setAllocation', $food->id, '-5')
        ->assertHasErrors('budget')
        ->call('setAllocation', $food->id, '50.00')
        ->assertHasErrors('budget');

    expect($food->fresh()->limit_amount)->toBe(60000);
});

it('turns rollover on for a category and later months', function () {
    $january = monthlyBudgetPlan($this->user, '2026-01');
    $february = monthlyBudgetPlan($this->user, '2026-02');
    $januaryLine = monthlyBudgetLine($january, 'Groceries', 40000);
    $februaryLine = Budget::factory()->inPlan($february)->withCategory(Category::query()->find($januaryLine->category_id))->create(['name' => 'Groceries', 'limit_amount' => 40000]);

    monthlyBudgetPage($this->user, ['month' => '2026-01'])->call('setRollover', $januaryLine->id, true);

    expect($januaryLine->fresh()->rollover_enabled)->toBeTrue()
        ->and($februaryLine->fresh()->rollover_enabled)->toBeTrue()
        ->and($februaryLine->fresh()->rolled_over_amount)->toBe(40000);
});

it('refuses a move that the manager refuses and keeps the dialog open', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $dining = monthlyBudgetLine($plan, 'Dining', 20000);
    $groceries = monthlyBudgetLine($plan, 'Groceries', 40000);

    monthlyBudgetPage($this->user)
        ->set('showMoveModal', true)
        ->set('moveFromId', (string) $dining->id)
        ->set('moveToId', (string) $groceries->id)
        ->set('moveAmount', '250.00')
        ->call('moveMoney')
        ->assertHasErrors('move')
        ->assertSet('showMoveModal', true);

    expect($dining->fresh()->limit_amount)->toBe(20000);

    $event = AuditEvent::query()->where('action', 'livewire.monthly-budget.moveMoney')->sole();

    expect($event->outcome)->toBe(AuditOutcome::Failure);
});

it('validates the move form before calling the manager', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $dining = monthlyBudgetLine($plan, 'Dining', 20000);

    monthlyBudgetPage($this->user)
        ->set('moveFromId', (string) $dining->id)
        ->set('moveToId', '')
        ->set('moveAmount', '5')
        ->call('moveMoney')
        ->assertHasErrors('move')
        ->set('moveToId', (string) $dining->id)
        ->set('moveAmount', '0')
        ->call('moveMoney')
        ->assertHasErrors('move');

    expect($dining->fresh()->limit_amount)->toBe(20000);
});

it('closes the move dialog and clears the form after a move', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $dining = monthlyBudgetLine($plan, 'Dining', 20000);
    $groceries = monthlyBudgetLine($plan, 'Groceries', 40000);

    monthlyBudgetPage($this->user)
        ->set('showMoveModal', true)
        ->set('moveFromId', (string) $dining->id)
        ->set('moveToId', (string) $groceries->id)
        ->set('moveAmount', '10')
        ->call('moveMoney')
        ->assertSet('showMoveModal', false)
        ->assertSet('moveAmount', '')
        ->assertSet('moveFromId', '');
});

it('shows the uncategorized remainder and the income figures', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03', total: 100000, expectedIncome: 400000);
    $groceries = monthlyBudgetLine($plan, 'Groceries', 30000);
    $salary = monthlyBudgetLine($plan, 'Salary', 0);
    monthlyBudgetSpend($this->user, $groceries, 5000, '2026-03-05');
    monthlyBudgetSpend($this->user, $salary, 300000, '2026-03-06', TransactionDirection::Credit);

    $page = monthlyBudgetPage($this->user)
        ->assertSeeHtml('data-testid="uncategorized-remainder"')
        ->assertSee('$700.00');

    $page->assertSeeHtml('data-testid="budgeted-figures"')->assertSee('$4,000.00');

    $page->set('tab', 'actual')
        ->assertSeeHtml('data-testid="actual-figures"')
        ->assertSee('$3,000.00')
        ->assertSee('75%');
});

it('hides the uncategorized remainder once everything is allocated', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03', total: 30000);
    monthlyBudgetLine($plan, 'Groceries', 30000);

    monthlyBudgetPage($this->user)->assertDontSeeHtml('data-testid="uncategorized-remainder"');
});

it('lists subcategories under their parent', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $food = monthlyBudgetLine($plan, 'Food', 60000);
    monthlyBudgetLine($plan, 'Groceries', 10000, Category::query()->find($food->category_id));
    monthlyBudgetLine($plan, 'Transport', 5000);

    $names = collect(monthlyBudgetPage($this->user)->instance()->rows)->map(fn (array $row): string => $row['line']->name.':'.$row['depth'])->all();

    expect($names)->toBe(['Food:0', 'Groceries:1', 'Transport:0']);
});

it('counts unbudgeted spending as over budget for the status filter', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $line = monthlyBudgetLine($plan, 'Dining', 0);
    monthlyBudgetLine($plan, 'Groceries', 10000);
    monthlyBudgetSpend($this->user, $line, 2000, '2026-03-05');

    $page = monthlyBudgetPage($this->user)->assertSeeHtml('data-testid="status-filter"')->set('filter', 'over');

    expect(collect($page->instance()->rows)->map(fn (array $row): string => $row['line']->name)->all())->toBe(['Dining']);
});

it('adds lines for categories created after the month was opened', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    monthlyBudgetLine($plan, 'Groceries', 10000);
    $fresh = Category::factory()->for($this->user)->create(['name' => 'Pets']);

    monthlyBudgetPage($this->user)->assertSee('Pets');

    expect($plan->lines()->where('category_id', $fresh->id)->exists())->toBeTrue();
});

it('clears an earlier error once a corrected write succeeds', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $line = monthlyBudgetLine($plan, 'Groceries', 10000);

    monthlyBudgetPage($this->user)
        ->call('setAllocation', $line->id, 'abc')
        ->assertHasErrors('budget')
        ->call('setAllocation', $line->id, '20.00')
        ->assertHasNoErrors();

    expect($line->fresh()->limit_amount)->toBe(2000);
});

it('refreshes spending after a transaction is saved elsewhere', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $line = monthlyBudgetLine($plan, 'Groceries', 10000);

    $page = monthlyBudgetPage($this->user);
    expect($page->instance()->summary->lines[0]->actual)->toBe(0);

    monthlyBudgetSpend($this->user, $line, 3000, '2026-03-05');
    $page->dispatch('transaction-saved');

    expect($page->instance()->summary->lines[0]->actual)->toBe(3000);
});

it('clears the move form and errors when the month changes', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03');
    $dining = monthlyBudgetLine($plan, 'Dining', 20000);

    monthlyBudgetPage($this->user)
        ->set('moveFromId', (string) $dining->id)
        ->set('moveAmount', '5')
        ->set('showMoveModal', true)
        ->set('month', '2026-02')
        ->assertSet('moveFromId', '')
        ->assertSet('moveAmount', '')
        ->assertSet('showMoveModal', false);
});

it('shows spending against a zero budget as a full donut and marks the active filter', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03', total: 0);
    $line = monthlyBudgetLine($plan, 'Dining', 0);
    $other = monthlyBudgetLine($plan, 'Groceries', 0);
    monthlyBudgetSpend($this->user, $line, 2000, '2026-03-05');

    monthlyBudgetPage($this->user)
        ->assertSee('100% of the budget spent')
        ->assertSeeHtml('aria-pressed="true"')
        ->set('filter', 'over')
        ->assertSeeHtml('data-testid="filter-over"');

    expect($other->exists)->toBeTrue();
});

it('keeps unsaved header figures while a category write runs', function () {
    $plan = monthlyBudgetPlan($this->user, '2026-03', total: 100000, expectedIncome: 200000);
    $line = monthlyBudgetLine($plan, 'Groceries', 10000);

    monthlyBudgetPage($this->user)
        ->set('totalInput', '1234.00')
        ->set('incomeInput', '4321.00')
        ->call('setAllocation', $line->id, '20.00')
        ->assertSet('totalInput', '1234.00')
        ->assertSet('incomeInput', '4321.00');

    expect($line->fresh()->limit_amount)->toBe(2000);
});
