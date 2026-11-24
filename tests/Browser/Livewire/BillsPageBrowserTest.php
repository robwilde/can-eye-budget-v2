<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlanOrigin;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the bills page lists sections, searches all bills and opens the payment panel', function () {
    config(['budget.bills_page' => true]);
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $gym = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Gym', 'amount' => 3000, 'direction' => TransactionDirection::Debit, 'origin' => PlanOrigin::Declared, 'merchant_key' => null]);
    PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Rent', 'amount' => 100000, 'direction' => TransactionDirection::Debit, 'origin' => PlanOrigin::Declared, 'merchant_key' => null]);

    $this->actingAs($user);

    visit('/bills')
        ->assertSee('Bills')
        ->assertPresent('[data-testid="bills-kpis"]')
        ->assertSee('Gym')
        ->screenshot(filename: 'bills-overview')
        ->click('[data-testid="tab-all"]')
        ->wait(1)
        ->fill('[data-testid="bills-search"]', 'gym')
        ->wait(2)
        ->assertSee('Gym')
        ->assertDontSee('Rent')
        ->click("[data-testid=\"bill-{$gym->id}\"] [data-testid=\"bill-find\"]")
        ->wait(2)
        ->assertPresent('[data-testid="occurrence-payment-panel"]')
        ->assertNoJavaScriptErrors();
});
