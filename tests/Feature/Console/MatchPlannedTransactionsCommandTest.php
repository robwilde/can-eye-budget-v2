<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 6, 15));
});

function seedMatchableRent(): Transaction
{
    $user = User::factory()->create(['id' => 4242]);
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'start_date' => '2026-06-10',
        'is_active' => true,
    ]);

    return Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);
}

test('matches a single user by id and reports the count', function () {
    $tx = seedMatchableRent();

    $this->artisan('app:match-planned-transactions', ['--user' => 4242])
        ->expectsOutputToContain('Matched 1 transaction(s).')
        ->assertSuccessful();

    expect($tx->fresh()->planned_transaction_id)->not->toBeNull();
});

test('fails when the user id does not exist', function () {
    $this->artisan('app:match-planned-transactions', ['--user' => 999999])
        ->assertFailed();
});

test('processes all users with active plans when no id is given and is idempotent', function () {
    $tx = seedMatchableRent();

    $this->artisan('app:match-planned-transactions')->assertSuccessful();
    $this->artisan('app:match-planned-transactions')
        ->expectsOutputToContain('Matched 0 transaction(s).')
        ->assertSuccessful();

    expect($tx->fresh()->planned_transaction_id)->not->toBeNull();
});
