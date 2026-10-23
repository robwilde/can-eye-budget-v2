<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('rebuilds the history of one users accounts only', function () {
    $this->travelTo('2026-05-12');
    $user = User::factory()->create();
    $mine = Account::factory()->for($user)->count(2)->create(['balance_source' => ImportSource::Manual]);
    $other = Account::factory()->create();

    $this->artisan('balances:rebuild', ['--user' => $user->id])->assertSuccessful();

    expect(AccountDailyBalance::query()->forUser($user)->count())->toBe(2)
        ->and(AccountDailyBalance::query()->forUser($other->user_id)->count())->toBe(0)
        ->and(AccountDailyBalance::query()->count())->toBe(2)
        ->and($mine->first()->dailyBalances()->sole()->end_balance)->toBe($mine->first()->balance);
});

test('rebuilds every account without a user filter', function () {
    $this->travelTo('2026-05-12');
    Account::factory()->count(3)->create();

    $this->artisan('balances:rebuild')->assertSuccessful();

    expect(AccountDailyBalance::query()->count())->toBe(3);
});

test('fails without rebuilding when the user does not exist', function () {
    $this->travelTo('2026-05-12');
    Account::factory()->create();

    $this->artisan('balances:rebuild', ['--user' => 999999])
        ->expectsOutputToContain('User 999999 not found.')
        ->assertFailed();

    expect(AccountDailyBalance::query()->count())->toBe(0);
});

test('reports a failed account, rebuilds the rest and exits with failure', function () {
    $this->travelTo('2026-05-12');
    [$broken, $healthy] = Account::factory()->count(2)->create()->all();

    DB::beforeExecuting(function (string $query, array $bindings) use ($broken): void {
        if (str_starts_with($query, 'delete') && str_contains($query, 'account_daily_balances') && ($bindings[0] ?? null) === $broken->id) {
            throw new RuntimeException('rebuild failed');
        }
    });

    $this->artisan('balances:rebuild')
        ->expectsOutputToContain("Account {$broken->id}: RuntimeException")
        ->expectsOutputToContain('Rebuilt 1 account(s); 1 failed.')
        ->assertFailed();

    expect($broken->dailyBalances()->exists())->toBeFalse()
        ->and($healthy->dailyBalances()->count())->toBe(1);
});
