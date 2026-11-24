<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionType;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;

test('the rules page scans, lists and accepts a streaming pattern as an autopay subscription', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);

    foreach ([1, 2, 3] as $monthsAgo) {
        Transaction::factory()->fromRedbark()->for($user)->for($account)->create([
            'merchant_name' => 'Netflix', 'description' => 'NETFLIX.COM', 'amount' => 1699, 'direction' => TransactionDirection::Debit,
            'post_date' => now()->subMonths($monthsAgo)->toDateString(), 'planned_transaction_id' => null,
        ]);
    }

    $this->actingAs($user);

    visit('/rules')
        ->wait(1)
        ->click('Find recurring')
        ->wait(3)
        ->assertSee('Netflix')
        ->screenshot(filename: 'recurring-detection')
        ->click('Accept')
        ->wait(2)
        ->assertNoJavaScriptErrors();

    $plan = PlannedTransaction::query()->where('user_id', $user->id)->sole();

    expect($plan->type)->toBe(PlannedTransactionType::Subscription)->and($plan->autopay)->toBeTrue();
});
