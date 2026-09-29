<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function migrationFeedRow(User $user, int $cents): Transaction
{
    return Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => Account::factory()->for($user)->create()->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => '2026-09-10',
        'description' => 'Transfer',
    ]);
}

function pairRows(Transaction $a, Transaction $b, string $column, string $source): void
{
    DB::table('transactions')->where('id', $a->id)->update([$column => $b->id, 'transfer_link_source' => $source]);
    DB::table('transactions')->where('id', $b->id)->update([$column => $a->id, 'transfer_link_source' => $source]);
}

function column(Transaction $row, string $name): mixed
{
    return DB::table('transactions')->where('id', $row->id)->value($name);
}

test('up turns a pair an earlier build linked at suggestion time into a pending suggestion and leaves confirmed links', function () {
    $migration = require database_path('migrations/2026_09_29_180001_convert_suggested_links_to_pending_suggestions.php');
    $user = User::factory()->create();
    [$debit, $credit] = [migrationFeedRow($user, -5000), migrationFeedRow($user, 5000)];
    [$confirmedDebit, $confirmedCredit] = [migrationFeedRow($user, -700), migrationFeedRow($user, 700)];
    pairRows($debit, $credit, 'transfer_pair_id', 'suggested');
    pairRows($confirmedDebit, $confirmedCredit, 'transfer_pair_id', 'confirmed');

    $migration->up();

    expect(column($debit, 'suggested_pair_id'))->toBe($credit->id)
        ->and(column($debit, 'transfer_pair_id'))->toBeNull()
        ->and(column($credit, 'suggested_pair_id'))->toBe($debit->id)
        ->and(column($credit, 'transfer_pair_id'))->toBeNull()
        ->and(column($confirmedDebit, 'transfer_pair_id'))->toBe($confirmedCredit->id)
        ->and(column($confirmedDebit, 'suggested_pair_id'))->toBeNull();
});

test('down restores pending suggestions to transfer_pair_id so a rollback loses no pair', function () {
    $migration = require database_path('migrations/2026_09_29_180001_convert_suggested_links_to_pending_suggestions.php');
    $user = User::factory()->create();
    [$debit, $credit] = [migrationFeedRow($user, -5000), migrationFeedRow($user, 5000)];
    pairRows($debit, $credit, 'suggested_pair_id', 'suggested');

    $migration->down();

    expect(column($debit, 'transfer_pair_id'))->toBe($credit->id)
        ->and(column($credit, 'transfer_pair_id'))->toBe($debit->id);
});

test('running up twice is harmless', function () {
    $migration = require database_path('migrations/2026_09_29_180001_convert_suggested_links_to_pending_suggestions.php');
    $user = User::factory()->create();
    [$debit, $credit] = [migrationFeedRow($user, -5000), migrationFeedRow($user, 5000)];
    pairRows($debit, $credit, 'transfer_pair_id', 'suggested');

    $migration->up();
    $migration->up();

    expect(column($debit, 'suggested_pair_id'))->toBe($credit->id)
        ->and(column($debit, 'transfer_pair_id'))->toBeNull();
});
