<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

test('accounts page shows categories grouped inline with own-segment names', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $office = Category::factory()->create(['name' => 'Office']);
    Category::factory()->withParent($office)->create(['name' => 'Software']);

    $this->actingAs($user);

    $page = visit('/accounts');

    $page->assertSee('Categories')
        ->assertSee('Office')
        ->assertSee('Software')
        ->assertDontSee('Office / Software');
});

test('clicking a category row expands its recent transactions inline', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $office = Category::factory()->create(['name' => 'Office']);
    $software = Category::factory()->withParent($office)->create(['name' => 'Software']);

    Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $software->id,
        'description' => 'Adobe Subscription',
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/accounts');

    $page->assertSee('Software')
        ->assertDontSee('Adobe Subscription')
        ->click('Software')
        ->assertSee('Most recent transactions:')
        ->assertSee('Adobe Subscription');
});
