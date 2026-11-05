<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountDetail;

test('deleting an account deletes its detail', function () {
    $account = Account::factory()->loan()->create();
    AccountDetail::factory()->create(['account_id' => $account->id, 'original_principal' => 100000]);

    $account->delete();

    expect(AccountDetail::query()->count())->toBe(0);
});
