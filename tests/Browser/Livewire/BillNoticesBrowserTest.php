<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlanOrigin;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the bills overview shows an urgent trial notice', function () {
    config(['budget.bills_page' => true]);
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create(['description' => 'Streamy', 'amount' => 1500, 'direction' => TransactionDirection::Debit, 'origin' => PlanOrigin::Declared, 'merchant_key' => null, 'start_date' => now()->addDays(20)->toDateString(), 'trial_ends_on' => now()->addDays(2)->toDateString()]);

    $this->actingAs($user);

    visit('/bills')
        ->assertVisible('[data-testid="bill-notices"]')
        ->assertSee('Streamy')
        ->assertVisible('[data-testid="notice-urgent"]')
        ->assertNoJavaScriptErrors();
});
