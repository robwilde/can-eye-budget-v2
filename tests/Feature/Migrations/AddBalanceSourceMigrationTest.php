<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the balance_source migration backfills existing accounts from import_source', function () {
    $migration = require database_path('migrations/2026_08_23_100001_add_balance_source_to_accounts_table.php');
    $user = User::factory()->create();

    // Roll back to the pre-migration schema, insert a row the way it would have existed
    // before the column was added, then re-apply the migration.
    $migration->down();

    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Legacy Account',
        'type' => 'transaction',
        'institution' => 'Test Bank',
        'currency' => 'AUD',
        'balance' => 1000,
        'import_source' => 'csv',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('accounts')->where('id', $accountId)->value('balance_source'))->toBe('csv');
});

test('a newly created account with no real balance stamped yet has a null balance source', function () {
    $account = Account::factory()->create();

    expect($account->balance_source)->toBeNull()
        ->and($account->balance_updated_at)->toBeNull();
});
