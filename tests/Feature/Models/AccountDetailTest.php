<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Models\Account;
use App\Models\AccountDetail;

test('deleting an account deletes its detail', function () {
    $account = Account::factory()->loan()->create();
    AccountDetail::factory()->create(['account_id' => $account->id, 'original_principal' => 100000]);

    $account->delete();

    expect(AccountDetail::query()->count())->toBe(0);
});

test('detail states keep the account they are given', function (string $state) {
    $account = Account::factory()->create();

    $detail = AccountDetail::factory()->for($account)->{$state}()->create();

    expect($detail->account_id)->toBe($account->id)
        ->and(Account::query()->count())->toBe(1);
})->with(['loan', 'creditCard']);

test('detail states create a matching account when none is given', function (string $state, AccountClass $type) {
    $detail = AccountDetail::factory()->{$state}()->create();

    expect($detail->account->type)->toBe($type)
        ->and(Account::query()->count())->toBe(1);
})->with([
    'loan' => ['loan', AccountClass::Loan],
    'card' => ['creditCard', AccountClass::CreditCard],
]);
