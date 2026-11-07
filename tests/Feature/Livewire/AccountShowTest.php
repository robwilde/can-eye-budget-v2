<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\LoanRateType;
use App\Livewire\AccountShow;
use App\Models\Account;
use App\Models\AccountDetail;
use App\Models\User;
use Livewire\Livewire;

it('shows the schedule tab for a loan', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->loan()->create();
    AccountDetail::factory()->create([
        'account_id' => $account->id,
        'rate_type' => LoanRateType::Fixed,
        'rate' => '6.000',
        'term_months' => 12,
        'start_date' => '2026-01-15',
        'original_principal' => 1000000,
    ]);

    $this->actingAs($user)->get(route('accounts.show', $account))->assertOk();

    Livewire::actingAs($user)->test(AccountShow::class, ['account' => $account])
        ->set('tab', 'schedule')
        ->assertSeeHtml('data-test="schedule-payment"')
        ->assertSee('$860.66')
        ->assertSee('15/01/2027');
});

it('asks for the missing loan terms instead of an empty schedule', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->loan()->create();

    Livewire::actingAs($user)->test(AccountShow::class, ['account' => $account])
        ->set('tab', 'schedule')
        ->assertSeeHtml('data-test="account-schedule-empty"');
});

it('does not offer a schedule for a savings account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create();

    Livewire::actingAs($user)->test(AccountShow::class, ['account' => $account])
        ->set('tab', 'schedule')
        ->assertSet('tab', 'overview')
        ->assertDontSeeHtml('data-test="account-tabs"');
});

it('shows card details for a credit card but not once it becomes a savings account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->creditCard()->create();
    AccountDetail::factory()->create(['account_id' => $account->id, 'minimum_payment' => 7500, 'annual_fee' => 39900]);

    Livewire::actingAs($user)->test(AccountShow::class, ['account' => $account])
        ->assertSee('Minimum payment')
        ->assertSee('Annual fee');

    $account->update(['type' => AccountClass::Savings]);

    Livewire::actingAs($user)->test(AccountShow::class, ['account' => $account->fresh()])
        ->assertDontSee('Minimum payment')
        ->assertDontSee('Annual fee');
});

it('returns not found for another users account', function () {
    $account = Account::factory()->for(User::factory()->create())->loan()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('accounts.show', $account))
        ->assertNotFound();
});
