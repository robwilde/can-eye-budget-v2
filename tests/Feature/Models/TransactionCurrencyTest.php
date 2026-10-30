<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('rejects a per-transaction exchange rate of zero', function (float $rate) {
    $account = Account::factory()->create(['currency' => 'AUD']);

    expect(fn () => Transaction::factory()->for($account->user)->for($account)->create(['currency' => 'EUR', 'exchange_rate' => $rate]))
        ->toThrow(ValidationException::class)
        ->and(Transaction::query()->count())->toBe(0);
})->with([0.0, -1.2, INF, NAN]);

it('rejects an update that sets a non-positive exchange rate', function () {
    $transaction = Transaction::factory()->create(['currency' => 'EUR', 'exchange_rate' => 1.5]);

    expect(fn () => $transaction->update(['exchange_rate' => 0]))->toThrow(ValidationException::class)
        ->and($transaction->fresh()->exchange_rate)->toBe(1.5);
});

it('rejects an exchange rate that rounds to zero', function () {
    $account = Account::factory()->create(['currency' => 'AUD']);

    expect(fn () => Transaction::factory()->for($account->user)->for($account)->create(['currency' => 'EUR', 'exchange_rate' => 0.000000001]))
        ->toThrow(ValidationException::class)
        ->and(Transaction::query()->count())->toBe(0);
});

it('stores the exchange rate rounded to eight decimals', function () {
    $transaction = Transaction::factory()->create(['currency' => 'EUR', 'exchange_rate' => 1.123456789]);

    expect($transaction->fresh()->exchange_rate)->toBe(1.12345679);
});

it('defaults the currency to the account currency', function () {
    $account = Account::factory()->create(['currency' => 'EUR']);

    $transaction = Transaction::factory()->for($account->user)->for($account)->create();

    expect($transaction->fresh()->currency)->toBe('EUR')
        ->and($transaction->fresh()->exchange_rate)->toBeNull();
});

it('keeps an explicit currency that differs from the account currency', function () {
    $account = Account::factory()->create(['currency' => 'AUD']);

    $transaction = Transaction::factory()->for($account->user)->for($account)->create(['currency' => 'usd', 'exchange_rate' => 1.5]);

    expect($transaction->fresh()->currency)->toBe('USD')
        ->and($transaction->fresh()->exchange_rate)->toBe(1.5);
});

it('rejects a currency that is not a three-letter code', function (string $code) {
    expect(fn () => Transaction::factory()->create(['currency' => $code]))->toThrow(ValidationException::class);
})->with(['EU', 'EURO', 'E1R']);

it('carries currency and rate to a new transaction version', function () {
    $account = Account::factory()->create(['currency' => 'AUD']);
    $transaction = Transaction::factory()->for($account->user)->for($account)->create(['currency' => 'EUR', 'exchange_rate' => 1.65]);

    $child = $transaction->createChild(['notes' => 'edited']);

    expect($child->fresh()->currency)->toBe('EUR')
        ->and($child->fresh()->exchange_rate)->toBe(1.65);
});

it('lets a new version change the currency and clear the rate', function () {
    $transaction = Transaction::factory()->create(['currency' => 'EUR', 'exchange_rate' => 1.65]);

    $child = $transaction->createChild(['currency' => 'USD', 'exchange_rate' => null]);

    expect($child->fresh()->currency)->toBe('USD')
        ->and($child->fresh()->exchange_rate)->toBeNull();
});

it('backfills transaction currency from the account', function () {
    $user = User::factory()->create();
    $euro = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $dollar = Account::factory()->for($user)->create(['currency' => 'USD']);
    $migration = require database_path('migrations/2026_10_24_300000_add_currency_and_exchange_rate_to_transactions_table.php');

    $rows = collect([$euro, $dollar, $euro])->map(function (Account $account) use ($user): array {
        $attributes = Transaction::factory()->make(['user_id' => $user->id, 'account_id' => $account->id])->getAttributes();
        unset($attributes['currency'], $attributes['exchange_rate']);

        return $attributes;
    });

    $migration->down();
    Schema::getConnection()->table('transactions')->insert($rows->all());
    $migration->up();

    expect(Transaction::query()->pluck('currency', 'id')->values()->all())->toBe(['EUR', 'USD', 'EUR']);
});

it('looks the default currency up only among the transaction owners accounts', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $foreignEuroAccount = Account::factory()->for($stranger)->create(['currency' => 'EUR']);

    $transaction = Transaction::factory()->create(['user_id' => $owner->id, 'account_id' => $foreignEuroAccount->id]);

    expect($transaction->fresh()->currency)->toBe('AUD');
});

it('makes a new version follow the new account when the currency was the old accounts default', function () {
    $user = User::factory()->create();
    $aud = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($user)->for($aud)->create();

    $child = $transaction->createChild(['account_id' => $eur->id]);

    expect($child->fresh()->currency)->toBe('EUR')
        ->and($transaction->fresh()->currency)->toBe('AUD');
});

it('clears the exchange rate when a moved version takes the new accounts currency', function () {
    $user = User::factory()->create();
    $aud = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($user)->for($aud)->create();

    $child = $transaction->createChild(['account_id' => $eur->id, 'exchange_rate' => 1.5]);

    expect($child->fresh()->currency)->toBe('EUR')
        ->and($child->fresh()->exchange_rate)->toBeNull();
});

it('keeps an explicitly chosen currency when the version moves account', function () {
    $user = User::factory()->create();
    $aud = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($user)->for($aud)->create();

    $child = $transaction->createChild(['account_id' => $eur->id, 'currency' => 'USD', 'exchange_rate' => 1.4]);

    expect($child->fresh()->currency)->toBe('USD')
        ->and($child->fresh()->exchange_rate)->toBe(1.4);
});

it('keeps a deliberate foreign currency but drops its stale rate when the version moves to an account in another currency', function () {
    $user = User::factory()->create();
    $aud = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($user)->for($aud)->create(['currency' => 'USD', 'exchange_rate' => 1.4]);

    $child = $transaction->createChild(['account_id' => $eur->id]);

    expect($child->fresh()->currency)->toBe('USD')
        ->and($child->fresh()->exchange_rate)->toBeNull();
});

it('keeps a foreign rate when the version moves to another account in the same currency', function () {
    $user = User::factory()->create();
    $first = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $second = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $transaction = Transaction::factory()->for($user)->for($first)->create(['currency' => 'USD', 'exchange_rate' => 1.4]);

    $child = $transaction->createChild(['account_id' => $second->id]);

    expect($child->fresh()->exchange_rate)->toBe(1.4);
});

it('keeps a rate supplied with the move', function () {
    $user = User::factory()->create();
    $aud = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($user)->for($aud)->create(['currency' => 'USD', 'exchange_rate' => 1.4]);

    $child = $transaction->createChild(['account_id' => $eur->id, 'exchange_rate' => 0.9]);

    expect($child->fresh()->exchange_rate)->toBe(0.9);
});

it('stores a large rate without losing its last decimals', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'AUD']);

    $transaction = Transaction::factory()->for($user)->for($account)->create(['currency' => 'VND', 'exchange_rate' => 1234567.12345678]);

    expect(DB::table('transactions')->where('id', $transaction->id)->value('exchange_rate'))->toBe(1234567.12345678);
});

it('keeps the currency when a new version stays on the same account', function () {
    $user = User::factory()->create();
    $aud = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $transaction = Transaction::factory()->for($user)->for($aud)->create();

    expect($transaction->createChild(['account_id' => $aud->id, 'notes' => 'x'])->fresh()->currency)->toBe('AUD');
});
