<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;

test('reports page renders the chart, both sections and mode tabs without JavaScript errors', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $spend = Category::factory()->create(['name' => 'Dining']);
    $earn = Category::factory()->create(['name' => 'Salary']);

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
    $rent = Category::factory()->create(['name' => 'Rent']);

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
    $office = Category::factory()->create(['name' => 'Office']);
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
    $category = Category::factory()->create(['name' => 'Utilities']);

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
