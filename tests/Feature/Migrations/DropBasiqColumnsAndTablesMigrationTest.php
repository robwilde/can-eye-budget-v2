<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the Basiq drop migration rewrites legacy Basiq rows and removes every Basiq column and table', function () {
    $migration = require database_path('migrations/2026_09_27_134813_drop_basiq_columns_and_tables.php');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->create();

    // Back to the pre-drop schema, then shape the rows the way a Basiq sync left them.
    $migration->down();

    DB::table('accounts')->where('id', $account->id)->update([
        'import_source' => 'basiq',
        'balance_source' => 'basiq',
        'basiq_account_id' => 'acc-1',
    ]);
    DB::table('transactions')->where('id', $transaction->id)->update([
        'source' => 'basiq',
        'basiq_id' => 'txn-1',
        'basiq_account_id' => 'acc-1',
    ]);
    DB::table('users')->where('id', $user->id)->update(['basiq_user_id' => 'user-1', 'last_synced_at' => now()]);
    DB::table('basiq_refresh_logs')->insert([
        'user_id' => $user->id,
        'trigger' => 'manual',
        'status' => 'success',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $account = Account::query()->findOrFail($account->id);

    expect($account->import_source)->toBe(ImportSource::Manual)
        ->and($account->balance_source)->toBe(ImportSource::Manual)
        ->and(Transaction::query()->findOrFail($transaction->id)->source)->toBe(TransactionSource::Csv)
        ->and(Schema::hasColumns('transactions', ['basiq_id']))->toBeFalse()
        ->and(Schema::hasColumn('transactions', 'basiq_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('accounts', 'basiq_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'basiq_user_id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'last_synced_at'))->toBeFalse()
        ->and(Schema::hasTable('basiq_refresh_logs'))->toBeFalse();
});
