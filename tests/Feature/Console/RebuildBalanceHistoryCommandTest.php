<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\User;

test('rebuilds the history of one users accounts only', function () {
    $this->travelTo('2026-05-12');
    $user = User::factory()->create();
    $mine = Account::factory()->for($user)->count(2)->create(['balance_source' => ImportSource::Manual]);
    Account::factory()->create();

    $this->artisan('balances:rebuild', ['--user' => $user->id])->assertSuccessful();

    expect(AccountDailyBalance::query()->forUser($user)->count())->toBe(2)
        ->and(AccountDailyBalance::query()->count())->toBe(2)
        ->and($mine->first()->dailyBalances()->sole()->end_balance)->toBe($mine->first()->balance);
});

test('rebuilds every account without a user filter', function () {
    $this->travelTo('2026-05-12');
    Account::factory()->count(3)->create();

    $this->artisan('balances:rebuild')->assertSuccessful();

    expect(AccountDailyBalance::query()->count())->toBe(3);
});
