<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\PlannedTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('backfills plan types from direction, destination and bnpl links', function () {
    $migration = require database_path('migrations/2026_11_28_000000_add_series_fields_to_planned_transactions_table.php');

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = fn (array $attributes = []) => PlannedTransaction::factory()->for($user)->for($account)->create($attributes)->id;

    $creditId = $plan(['direction' => TransactionDirection::Credit]);
    $destinationId = $plan(['transfer_to_account_id' => Account::factory()->for($user)->create()->id]);
    $creditTransferId = $plan([
        'direction' => TransactionDirection::Credit,
        'transfer_to_account_id' => Account::factory()->for($user)->create()->id,
    ]);
    $bnplId = $plan(['frequency' => RecurrenceFrequency::Every2Weeks]);
    $plainId = $plan();

    BnplOrder::factory()->for($user)->create(['account_id' => $account->id, 'planned_transaction_id' => $bnplId]);

    $migration->down();
    $migration->up();

    $types = DB::table('planned_transactions')->pluck('type', 'id')->all();

    expect($types[$creditId])->toBe('income')
        ->and($types[$destinationId])->toBe('transfer')
        ->and($types[$creditTransferId])->toBe('transfer')
        ->and($types[$bnplId])->toBe('installment')
        ->and($types[$plainId])->toBe('bill');
});

it('leaves existing plans declared with the default tuning after the migration', function () {
    $migration = require database_path('migrations/2026_11_28_000000_add_series_fields_to_planned_transactions_table.php');
    $id = PlannedTransaction::factory()->create()->id;

    $migration->down();
    $migration->up();

    $row = DB::table('planned_transactions')->where('id', $id)->first();

    expect($row->origin)->toBe('declared')
        ->and($row->autopay)->toBe(0)
        ->and($row->reminder_days)->toBe(3)
        ->and($row->grace_days)->toBe(3)
        ->and($row->amount_tolerance_bps)->toBe(750)
        ->and($row->match_early_days)->toBe(2)
        ->and($row->match_late_days)->toBe(7)
        ->and($row->amount_strategy)->toBe('fixed');
});
