<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlanOrigin;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the bills calendar browses months and the transactions page projects upcoming items', function () {
    config(['budget.bills_page' => true]);
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create(['description' => 'Gym', 'amount' => 3000, 'direction' => TransactionDirection::Debit, 'origin' => PlanOrigin::Declared, 'merchant_key' => null, 'start_date' => now()->addDays(3)->toDateString()]);

    $this->actingAs($user);

    visit('/bills')
        ->click('[data-testid="tab-calendar"]')
        ->wait(2)
        ->assertVisible('[data-testid="bills-calendar"]')
        ->assertVisible('[data-testid="calendar-totals"]')
        ->screenshot(filename: 'bills-calendar')
        ->click('[data-testid="calendar-next"]')
        ->wait(2)
        ->assertVisible('[data-testid="bills-calendar"]')
        ->assertNoJavaScriptErrors();

    visit('/transactions')
        ->assertSee('Coming up in the next 10 days')
        ->assertSee('Gym')
        ->assertNoJavaScriptErrors();
});
