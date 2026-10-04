<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the clean_description_source migration marks only existing names as manual', function () {
    $migration = require database_path('migrations/2026_10_04_120000_add_clean_description_source_to_transactions_table.php');

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $migration->down();

    $base = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 1000,
        'direction' => 'debit',
        'description' => 'NETFLIX.COM',
        'post_date' => now()->toDateString(),
        'status' => 'posted',
        'source' => 'csv',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $namedId = DB::table('transactions')->insertGetId([...$base, 'clean_description' => 'Netflix']);
    $nullId = DB::table('transactions')->insertGetId([...$base, 'clean_description' => null]);
    $emptyId = DB::table('transactions')->insertGetId([...$base, 'clean_description' => '']);

    $migration->up();

    expect(DB::table('transactions')->where('id', $namedId)->value('clean_description_source'))->toBe('manual')
        ->and(DB::table('transactions')->where('id', $nullId)->value('clean_description_source'))->toBeNull()
        ->and(DB::table('transactions')->where('id', $emptyId)->value('clean_description_source'))->toBeNull();
});
