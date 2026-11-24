<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\User;

test('the rules page plan editor lists recorded price changes', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Gym', 'amount' => 6000]);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 5000, 'new_amount' => 6000, 'effective_on' => '2026-04-10']);

    $this->actingAs($user);

    visit('/rules')
        ->assertSee('Gym')
        ->click("button[wire\\:click=\"openEdit({$plan->id})\"]")
        ->assertPresent('[data-testid="plan-price-changes"]')
        ->assertSee('2026-04-10: $50.00 → $60.00')
        ->assertNoJavaScriptErrors();
});
