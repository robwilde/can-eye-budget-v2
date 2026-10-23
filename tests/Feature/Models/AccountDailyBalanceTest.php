<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountDailyBalance;

test('gives each factory made row for one account its own date', function () {
    $this->freezeTime();

    $account = Account::factory()->create();

    $rows = AccountDailyBalance::factory()->for($account)->count(60)->create();

    expect($rows->map(fn (AccountDailyBalance $row): string => $row->balance_date->toDateString())->unique())->toHaveCount(60);
});
