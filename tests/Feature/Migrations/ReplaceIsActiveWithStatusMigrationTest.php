<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
use App\Models\PlannedTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills plan status from is_active', function () {
    $migration = require database_path('migrations/2026_12_03_000000_replace_is_active_with_status_on_planned_transactions_table.php');
    $active = PlannedTransaction::factory()->create()->id;
    $paused = PlannedTransaction::factory()->inactive()->create()->id;

    $migration->down();

    expect(Schema::hasColumn('planned_transactions', 'is_active'))->toBeTrue()
        ->and(Schema::hasColumn('planned_transactions', 'status'))->toBeFalse()
        ->and((bool) DB::table('planned_transactions')->where('id', $active)->value('is_active'))->toBeTrue()
        ->and((bool) DB::table('planned_transactions')->where('id', $paused)->value('is_active'))->toBeFalse();

    DB::table('planned_transactions')->where('id', $active)->update(['is_active' => true]);
    DB::table('planned_transactions')->where('id', $paused)->update(['is_active' => false]);

    $migration->up();

    $statuses = DB::table('planned_transactions')->pluck('status', 'id')->all();

    expect($statuses[$active])->toBe('active')
        ->and($statuses[$paused])->toBe('inactive')
        ->and(Schema::hasColumn('planned_transactions', 'is_active'))->toBeFalse()
        ->and(PlannedTransaction::query()->find($paused)->status)->toBe(PlannedTransactionStatus::Inactive);
});

it('indexes plans by user and status', function () {
    expect(collect(Schema::getIndexes('planned_transactions'))->pluck('columns')->all())->toContain(['user_id', 'status']);
});
