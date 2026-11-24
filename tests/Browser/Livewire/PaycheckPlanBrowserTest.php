<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the dashboard shows the paycheck plan and offers more periods', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create(['balance' => 80000]);
    $user->update(['primary_account_id' => $account->id]);
    PlannedTransaction::factory()->for($user)->for($account)->create(['description' => 'Browser pay', 'amount' => 200000, 'direction' => TransactionDirection::Credit, 'type' => PlannedTransactionType::Income, 'frequency' => RecurrenceFrequency::Every2Weeks, 'start_date' => now()->addDays(4)->toDateString(), 'origin' => PlanOrigin::Declared, 'merchant_key' => null]);
    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create(['description' => 'Browser rent', 'amount' => 120000, 'direction' => TransactionDirection::Debit, 'type' => PlannedTransactionType::Bill, 'start_date' => now()->addDays(8)->toDateString(), 'origin' => PlanOrigin::Declared, 'merchant_key' => null]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->wait(2)->script('window.scrollTo(0, document.body.scrollHeight)');

    $page->wait(3)
        ->assertVisible('[data-testid="paycheck-plan"]')
        ->assertSee('Browser pay')
        ->screenshot(filename: 'paycheck-plan')
        ->click('[data-testid="paycheck-show-more"]')
        ->wait(2)
        ->assertDontSee('Show more periods')
        ->assertNoJavaScriptErrors();
});
