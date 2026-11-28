<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the budget plan currency migration backfills each plan with its owner base currency', function () {
    $migration = require database_path('migrations/2026_12_14_000100_add_currency_to_budget_plans_table.php');

    $euro = User::factory()->create(['base_currency' => 'EUR']);
    $kiwi = User::factory()->create(['base_currency' => 'NZD']);

    $migration->down();

    $base = [
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'total_amount' => 100000,
        'expected_income' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $euroPlanId = DB::table('budget_plans')->insertGetId([...$base, 'user_id' => $euro->id]);
    $kiwiPlanId = DB::table('budget_plans')->insertGetId([...$base, 'user_id' => $kiwi->id]);

    $migration->up();

    expect(DB::table('budget_plans')->where('id', $euroPlanId)->value('currency'))->toBe('EUR')
        ->and(DB::table('budget_plans')->where('id', $kiwiPlanId)->value('currency'))->toBe('NZD');
});
