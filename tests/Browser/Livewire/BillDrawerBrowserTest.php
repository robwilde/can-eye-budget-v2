<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlanOrigin;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the bills page opens a bill drawer from a row and from a direct link', function () {
    config(['budget.bills_page' => true]);
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create(['description' => 'Gym', 'amount' => 3000, 'direction' => TransactionDirection::Debit, 'origin' => PlanOrigin::Declared, 'merchant_key' => null, 'start_date' => now()->addDays(3)->toDateString()]);

    $this->actingAs($user);

    visit('/bills')
        ->click('[data-testid="bill-open"]')
        ->wait(2)
        ->assertPresent('[data-testid="drawer-cadence"]')
        ->assertSee("Don't repeat")
        ->screenshot(filename: 'bill-drawer')
        ->assertNoJavaScriptErrors();

    visit("/bills?plan={$plan->id}")
        ->wait(2)
        ->assertVisible('[data-testid="drawer-stats"]')
        ->assertNoJavaScriptErrors();
});
