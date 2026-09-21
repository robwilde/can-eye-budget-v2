<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the category_source migration backfills only rows that actually have a category', function () {
    $migration = require database_path('migrations/2026_09_21_100001_add_category_source_to_transactions_table.php');

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    // Roll back to the pre-migration schema, insert rows the way they would have
    // existed before the column was added, then re-apply the migration.
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

    $categorisedId = DB::table('transactions')->insertGetId([...$base, 'category_id' => $category->id]);
    $uncategorisedId = DB::table('transactions')->insertGetId([...$base, 'category_id' => null]);

    $migration->up();

    expect(DB::table('transactions')->where('id', $categorisedId)->value('category_source'))->toBe('manual')
        ->and(DB::table('transactions')->where('id', $uncategorisedId)->value('category_source'))->toBeNull();
});
