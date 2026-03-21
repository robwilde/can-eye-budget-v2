<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

test('spending chart renders area chart with data on dashboard', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->count(3)->create([
        'account_id' => $account->id,
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Spending Over Time');
});

test('period selector changes update the chart', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'post_date' => now()->subDays(3),
    ]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Spending Over Time')
        ->select('[data-testid="spending-over-time"] [wire\\:model\\.live="period"]', '7d')
        ->assertSee('Spending Over Time');
});

test('empty state displays when no transactions', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Spending Over Time')
        ->assertSee('No spending data');
});
