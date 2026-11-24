<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
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
        'status' => PlannedTransactionStatus::Active,
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

test('the page offers the csv export and a print link that carries its filters', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create(['name' => 'Holiday']);

    $this->actingAs($user);

    visit('/reports?tag='.$tag->id)
        ->assertPresent('[data-testid="export-csv"]')
        ->assertAttributeContains('[data-testid="print-report"]', 'href', '/reports/print')
        ->assertAttributeContains('[data-testid="print-report"]', 'href', 'tag='.$tag->id)
        ->click('[data-testid="export-csv"]')
        ->assertNoJavaScriptErrors();
});

test('the print view shows the report totals without the app chrome', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $spend = Category::factory()->for($user)->create(['name' => 'Dining']);
    $earn = Category::factory()->for($user)->create(['name' => 'Salary']);

    Transaction::factory()->for($user)->debit()->create(['account_id' => $account->id, 'category_id' => $spend->id, 'amount' => 30000, 'post_date' => now()]);
    Transaction::factory()->for($user)->credit()->create(['account_id' => $account->id, 'category_id' => $earn->id, 'amount' => 400000, 'post_date' => now()]);

    $this->actingAs($user);

    visit('/reports/print')
        ->assertSee('Dining')
        ->assertSee('Salary')
        ->assertSeeIn('[data-testid="print-income"]', '$4,000.00')
        ->assertSeeIn('[data-testid="print-expenses"]', '$300.00')
        ->assertSeeIn('[data-testid="print-net"]', '$3,700.00')
        ->assertPresent('[data-testid="print-button"]')
        ->assertMissing('[data-flux-sidebar-item]')
        ->assertNoJavaScriptErrors();
});

test('reports sections can be collapsed and reordered, and the order survives a reload', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 500000]);
    $spend = Category::factory()->for($user)->create(['name' => 'Dining']);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $spend->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);

    $this->actingAs($user);

    $order = 'Array.from(document.querySelectorAll("section[data-testid^=report-section-]")).map((section) => section.dataset.testid.replace("report-section-", "")).join(",")';

    $hint = static fn (string $text): string => 'Array.from(document.querySelectorAll("[data-testid=report-net-worth] .mhint")).some((hint) => hint.textContent.trim() === '.json_encode($text).')';

    visit('/reports')
        ->assertPresent('[data-testid="report-net-worth"]')
        ->assertPresent('[data-testid="trends-table"]')
        ->assertPresent('.apexcharts-canvas')
        ->assertScript("{$order} === 'spending,net-worth,trends'")
        ->click('[data-testid="report-section-up-trends"]')
        ->assertScript("{$order} === 'spending,trends,net-worth'")
        ->click('[data-testid="report-section-down-spending"]')
        ->assertScript("{$order} === 'trends,spending,net-worth'")
        ->assertPresent('.apexcharts-canvas')
        ->click('[data-testid="report-section-up-spending"]')
        ->assertScript("{$order} === 'spending,trends,net-worth'")
        ->click('[data-testid="report-section-toggle-trends"]')
        ->assertMissing('[data-testid="trends-table"]')
        ->click('[data-testid="report-section-toggle-trends"]')
        ->assertVisible('[data-testid="trends-table"]')
        ->assertScript($hint(now()->startOfMonth()->format('j M Y')))
        ->click('[data-testid="period-prev"]')
        ->assertScript($hint(now()->startOfMonth()->subMonthNoOverflow()->format('j M Y')))
        ->assertSeeIn('[data-testid="trends-table"]', now()->startOfMonth()->subMonthNoOverflow()->format('M Y'))
        ->refresh()
        ->assertScript("{$order} === 'spending,trends,net-worth'")
        ->assertNoJavaScriptErrors();
});

test('a collapsed spending section can be reopened after switching to the plan tab', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    visit('/reports')
        ->click('[data-testid="report-section-toggle-spending"]')
        ->click('Plan')
        ->assertPresent('[data-testid="report-section-toggle-spending"]')
        ->click('[data-testid="report-section-toggle-spending"]')
        ->assertVisible('#report-section-body-spending')
        ->assertNoJavaScriptErrors();
});
