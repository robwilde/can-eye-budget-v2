<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountOrder;
use App\Livewire\AccountManager;
use App\Livewire\AccountShow;
use App\Livewire\Dashboard;
use App\Livewire\Dashboard\NetWorth;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));
    $this->user = User::factory()->withPayCycle()->create();
});

it('shows net worth as assets minus debts', function () {
    Account::factory()->for($this->user)->create(['balance' => 20000000]);
    Account::factory()->savings()->for($this->user)->create(['balance' => 10000000]);
    Account::factory()->creditCard()->for($this->user)->create(['balance' => -10000000, 'credit_limit' => 50000000]);

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertSeeLivewire(NetWorth::class);

    Livewire::actingAs($this->user)
        ->test(NetWorth::class)
        ->assertSeeInOrder(['Net worth', '$200,000.00'])
        ->assertSee('Assets')
        ->assertSee('Liabilities');
});

it('keeps the net worth period to the offered choices', function () {
    Livewire::actingAs($this->user)
        ->test(NetWorth::class)
        ->set('days', 30)
        ->assertSet('days', 30)
        ->set('days', 7)
        ->assertSet('days', 90);
});

it('saves the account order preference from the balance sheet', function () {
    Livewire::actingAs($this->user)
        ->test(NetWorth::class)
        ->call('setAccountOrder', 'balance-desc');

    expect($this->user->fresh()->preferences->accountOrder)->toBe(AccountOrder::BalanceDesc);
});

it('ignores an account order that does not exist', function () {
    Livewire::actingAs($this->user)
        ->test(NetWorth::class)
        ->call('setAccountOrder', 'sideways');

    expect($this->user->fresh()->preferences->accountOrder)->toBe(AccountOrder::NameAsc);
});

it('falls back to name order when the stored account order is no longer valid', function () {
    $this->user->updatePreferences(['accountOrder' => 'removed-order']);

    expect($this->user->fresh()->preferences->accountOrder)->toBe(AccountOrder::NameAsc);
});

it('draws a balance chart on the account page and a sparkline on the account list', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 30000]);
    foreach (CarbonImmutable::parse('2026-07-01')->daysUntil('2026-07-16') as $index => $day) {
        AccountDailyBalance::factory()->for($account)->create([
            'balance_date' => $day->toDateString(),
            'start_balance' => 10000 + $index * 1000,
            'end_balance' => 10000 + $index * 1000,
        ]);
    }

    Livewire::actingAs($this->user)
        ->test(AccountShow::class, ['account' => $account])
        ->assertSeeHtml('data-test="account-balance-chart"')
        ->set('days', 30)
        ->assertSet('days', 30)
        ->set('days', 5)
        ->assertSet('days', 90);

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->assertSeeHtml('data-trend="favourable"');
});

it('colours a falling net worth as unfavourable', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 10000]);
    AccountDailyBalance::factory()->for($account)->create(['balance_date' => '2026-04-01', 'start_balance' => 90000, 'end_balance' => 90000]);
    AccountDailyBalance::factory()->for($account)->create(['balance_date' => '2026-07-15', 'start_balance' => 10000, 'end_balance' => 10000]);

    $component = Livewire::actingAs($this->user)->test(NetWorth::class);

    expect($component->instance()->favourable())->toBeFalse();
});
