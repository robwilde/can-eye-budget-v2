<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;

test('reports page renders the chart, both sections and mode tabs without JavaScript errors', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $spend = Category::factory()->for($user)->create(['name' => 'Dining']);
    $earn = Category::factory()->for($user)->create(['name' => 'Salary']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $spend->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'category_id' => $earn->id,
        'amount' => 900000,
        'post_date' => now(),
    ]);

    $this->actingAs($user);

    $page = visit('/reports');

    $page->assertSee('Expenses')
        ->assertSee('Incomes')
        ->assertSee('Real')
        ->assertSee('Plan')
        ->assertSee('Dining')
        ->assertSee('Salary')
        ->assertPresent('.apexcharts-canvas')
        ->assertPresent('.report-metric')
        ->assertPresent('.track .fill')
        ->assertNoJavaScriptErrors();
});

test('switching to the plan tab surfaces planned-only categories', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $rent = Category::factory()->for($user)->create(['name' => 'Rent']);

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $rent->id,
        'amount' => 200000,
        'direction' => TransactionDirection::Debit,
        'start_date' => now()->startOfMonth(),
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $page = visit('/reports');

    $page->assertDontSee('$2,000.00')
        ->click('Plan')
        ->assertSee('$2,000.00')
        ->assertNoJavaScriptErrors();
});

test('expanding a category row reveals its full-path subcategory inline', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $office = Category::factory()->for($user)->create(['name' => 'Office']);
    $software = Category::factory()->withParent($office)->create(['name' => 'Software']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $software->id,
        'amount' => 4500,
        'post_date' => now(),
    ]);

    $this->actingAs($user);

    $page = visit('/reports');

    $page->assertSee('Office')
        ->assertDontSee('Office / Software')
        ->click('.report-row[aria-expanded="false"]')
        ->assertSee('Office / Software')
        ->assertPresent('.track-sub .fill');
});

test('a leaf category links through to the filtered transaction list', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create(['name' => 'Utilities']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);

    $this->actingAs($user);

    $page = visit('/reports');

    $page->assertSee('Utilities')
        ->click("a[href*='category={$category->id}']")
        ->assertPathBeginsWith('/transactions')
        ->assertQueryStringHas('category', (string) $category->id)
        ->assertQueryStringHas('direction', 'outgoing');
});

test('the sidebar links through to the reports page', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->assertSeeIn('[data-flux-sidebar-item][href$="/reports"]', 'Reports')
        ->click('[data-flux-sidebar-item][href$="/reports"]')
        ->assertPathBeginsWith('/reports')
        ->assertSee('Incoming and outgoing by category');
});

test('the summary shows the change against the previous period and the budget card for this month', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create(['name' => 'Groceries']);
    BudgetPlan::factory()->for($user)->create([
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'total_amount' => 100000,
        'initialised_at' => now(),
    ]);

    foreach ([[6000, now()], [1000, now()->startOfMonth()]] as [$amount, $date]) {
        Transaction::factory()->for($user)->debit()->create(['account_id' => $account->id, 'category_id' => $category->id, 'amount' => $amount, 'post_date' => $date]);
    }
    Transaction::factory()->for($user)->credit()->create(['account_id' => $account->id, 'category_id' => $category->id, 'amount' => 1000, 'post_date' => now()]);
    Transaction::factory()->for($user)->debit()->create(['account_id' => $account->id, 'category_id' => $category->id, 'amount' => 3000, 'post_date' => now()->startOfMonth()->subDays(2)]);

    $this->actingAs($user);

    visit('/reports?period=custom&from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())
        ->assertSee('vs previous period')
        ->assertPresent('[data-testid="budget-performance"]')
        ->assertSeeIn('.report-row .tx-amt', '$60.00')
        ->assertNoJavaScriptErrors();
});

test('stepping to the previous month moves the report and drops the budget card', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create(['name' => 'Groceries']);
    BudgetPlan::factory()->for($user)->create([
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'total_amount' => 100000,
        'initialised_at' => now(),
    ]);
    Transaction::factory()->for($user)->debit()->create(['account_id' => $account->id, 'category_id' => $category->id, 'amount' => 4200, 'post_date' => now()->startOfMonth()->subDays(3)]);

    $this->actingAs($user);

    visit('/reports')
        ->assertPresent('[data-testid="budget-performance"]')
        ->assertDontSee('$42.00')
        ->click('[data-testid="period-prev"]')
        ->assertSee('$42.00')
        ->assertMissing('[data-testid="budget-performance"]')
        ->assertQueryStringHas('period', 'custom')
        ->assertNoJavaScriptErrors();
});

test('a backwards custom range is swapped with an alert', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/reports?period=custom&from=2026-03-31&to=2026-03-01')
        ->assertSee('The end date was before the start date')
        ->assertSee('2026-03-01 to 2026-03-31')
        ->assertNoJavaScriptErrors();
});

test('the tag filter limits the breakdown to tagged transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $tagged = Category::factory()->for($user)->create(['name' => 'Tagged spend']);
    $plain = Category::factory()->for($user)->create(['name' => 'Plain spend']);
    $tag = Tag::factory()->for($user)->create(['name' => 'Holiday']);

    Transaction::factory()->for($user)->debit()->create(['account_id' => $account->id, 'category_id' => $tagged->id, 'amount' => 3000, 'post_date' => now()])->tags()->attach($tag);
    Transaction::factory()->for($user)->debit()->create(['account_id' => $account->id, 'category_id' => $plain->id, 'amount' => 5000, 'post_date' => now()]);

    $this->actingAs($user);

    visit('/reports?tag='.$tag->id)
        ->assertSee('Tagged spend')
        ->assertDontSee('Plain spend')
        ->assertNoJavaScriptErrors();
});
