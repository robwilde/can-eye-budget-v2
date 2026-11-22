<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\Transaction;
use App\Models\User;

function investorWithHolding(): User
{
    $user = User::factory()->create();
    $account = Account::factory()->investment()->for($user)->create(['balance' => 100000]);
    $security = Security::factory()->create(['ticker' => 'VASX', 'currency' => 'AUD']);
    SecurityPrice::factory()->create(['security_id' => $security->id, 'currency' => 'AUD', 'date' => now()->subDay()->toDateString(), 'price' => 1100]);
    SecurityPrice::factory()->create(['security_id' => $security->id, 'currency' => 'AUD', 'date' => now()->toDateString(), 'price' => 1200]);
    Holding::factory()->create(['account_id' => $account->id, 'security_id' => $security->id, 'currency' => 'AUD', 'quantity' => '10', 'amount' => 12000, 'cost_basis_per_share' => 1000, 'cost_basis_source' => 'manual', 'date' => now()->toDateString()]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => 50000, 'direction' => TransactionDirection::Credit, 'status' => TransactionStatus::Posted, 'post_date' => now()->toDateString()]);

    return $user;
}

test('the dashboard shows the investment summary and the reports show the investment sections when the flag is on', function () {
    config(['budget.investments.enabled' => true]);
    $this->actingAs(investorWithHolding());

    visit('/dashboard')
        ->assertSee('Portfolio value')
        ->assertSee('VASX')
        ->assertNoJavaScriptErrors();

    visit('/reports?period=this-month')
        ->assertSee('Investment performance')
        ->assertSee('Investment flows')
        ->assertSee('Top holdings')
        ->assertSee('VASX')
        ->assertNoJavaScriptErrors();
});

test('the investment summary and sections are hidden when the flag is off', function () {
    config(['budget.investments.enabled' => false]);
    $this->actingAs(investorWithHolding());

    visit('/dashboard')
        ->assertDontSee('Portfolio value')
        ->assertNoJavaScriptErrors();

    visit('/reports?period=this-month')
        ->assertDontSee('Investment performance')
        ->assertDontSee('Investment flows')
        ->assertNoJavaScriptErrors();
});
