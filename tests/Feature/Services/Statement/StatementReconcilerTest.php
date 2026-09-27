<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\StatementLineKind;
use App\Enums\StatementLineResolution;
use App\Enums\StatementReconciliationStatus;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\Statement\StatementFileUnreadable;
use App\Exceptions\Statement\StatementLineAlreadyFolded;
use App\Exceptions\Statement\StatementLineNotResolvable;
use App\Exceptions\Statement\StatementReconciliationClosed;
use App\Exceptions\Statement\StatementReconciliationIncomplete;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\StatementReconciliation;
use App\Models\StatementReconciliationLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\Statement\StatementReconciler;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A January 2026 reconciliation over a Date,Description,Amount CSV built from $rows.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $rows  [d/m/Y, description, amount]
 */
function stmtReconciliation(array $rows, ?Account $account = null): StatementReconciliation
{
    $account ??= Account::factory()->for(User::factory())->create();

    $reconciliation = StatementReconciliation::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ]);

    stmtUpload($reconciliation, $rows);

    return $reconciliation;
}

/** @param  list<array{0: string, 1: string, 2: string}>  $rows */
function stmtUpload(StatementReconciliation $reconciliation, array $rows): void
{
    $csv = "Date,Description,Amount\n";

    foreach ($rows as [$date, $description, $amount]) {
        $csv .= "{$date},\"{$description}\",{$amount}\n";
    }

    Storage::disk('local')->put($reconciliation->stored_path, $csv);
}

function stmtFeedRow(StatementReconciliation $reconciliation, string $date, string $description, int $amount, TransactionStatus $status = TransactionStatus::Posted): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $reconciliation->user_id,
        'account_id' => $reconciliation->account_id,
        'post_date' => $date,
        'description' => $description,
        'amount' => $amount,
        'direction' => $amount < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'status' => $status,
        'source' => TransactionSource::Redbark,
    ]);
}

function stmtBuild(StatementReconciliation $reconciliation): StatementReconciliation
{
    app(StatementReconciler::class)->build($reconciliation);

    return $reconciliation->refresh();
}

/** @return list<string> kinds in line id order */
function stmtKinds(StatementReconciliation $reconciliation): array
{
    return $reconciliation->lines()->orderBy('id')->get()
        ->map(fn (StatementReconciliationLine $line): string => $line->kind->value)
        ->all();
}

function stmtLine(StatementReconciliation $reconciliation, StatementLineKind $kind): StatementReconciliationLine
{
    return $reconciliation->lines()->where('kind', $kind)->orderBy('id')->firstOrFail();
}

test('the Beyond Bank fixture splits into matched, statement-only and feed-only lines', function () {
    $account = Account::factory()->for(User::factory())->create();

    $reconciliation = StatementReconciliation::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'column_mapping' => [
            CsvColumnMapper::FIELD_DATE => 'Entered Date',
            CsvColumnMapper::FIELD_DESCRIPTION => 'Transaction Description',
            CsvColumnMapper::FIELD_AMOUNT => 'Amount',
            CsvColumnMapper::FIELD_BALANCE => 'Balance',
        ],
    ]);
    Storage::disk('local')->put(
        $reconciliation->stored_path,
        (string) file_get_contents(base_path('tests/Fixtures/Statement/BeyondBank-2026-01.csv')),
    );

    $netflix = stmtFeedRow($reconciliation, '2026-01-01', 'VISA -Netflix.com Melbourne AU 724493 #2892', -2899);
    $rent = stmtFeedRow($reconciliation, '2026-01-02', 'Ext Tfr - NET#4491106306 to 554078 Real Living WV WBC - 260 Queen Street', -75000);
    $salary = stmtFeedRow($reconciliation, '2026-01-04', 'Osko Payment From COMPARE BUILD PTY LTD Ref#884905699', 150000);
    $nib = stmtFeedRow($reconciliation, '2026-01-08', 'Direct Debit NIB - xxxx9390', -9059);
    $uber = stmtFeedRow($reconciliation, '2026-01-20', 'UBER *TRIP HELP.UBER.COM', -2350);
    stmtFeedRow($reconciliation, '2026-02-05', 'UBER *TRIP HELP.UBER.COM', -1800);

    stmtBuild($reconciliation);

    $lines = $reconciliation->lines()->orderBy('id')->get();

    expect($lines->map(fn (StatementReconciliationLine $line): array => [$line->post_date->toDateString(), $line->kind, $line->transaction_id])->all())
        ->toBe([
            ['2026-01-01', StatementLineKind::Matched, $netflix->id],
            ['2026-01-02', StatementLineKind::Matched, $rent->id],
            ['2026-01-03', StatementLineKind::Matched, $salary->id],
            ['2026-01-08', StatementLineKind::Matched, $nib->id],
            ['2026-01-09', StatementLineKind::StatementOnly, null],
            ['2026-01-12', StatementLineKind::StatementOnly, null],
            ['2026-01-20', StatementLineKind::FeedOnly, $uber->id],
        ])
        ->and($reconciliation->lines_outside_period)->toBe(2)
        ->and($reconciliation->statement_debit_total)->toBe(2899 + 75000 + 9059 + 75000)
        ->and($reconciliation->statement_credit_total)->toBe(150000 + 1650)
        ->and($reconciliation->closing_balance)->toBe(83412);
});

test('a hand-entered transaction neither matches a statement line nor shows as feed-only', function (TransactionSource $source) {
    $reconciliation = stmtReconciliation([['05/01/2026', 'WOOLWORTHS 1234 SYDNEY', '-42.50']]);
    $entered = stmtFeedRow($reconciliation, '2026-01-05', 'WOOLWORTHS 1234 SYDNEY', -4250);
    $entered->update(['source' => $source]);

    expect(stmtKinds(stmtBuild($reconciliation)))->toBe(['statement_only']);
})->with([
    'manual' => [TransactionSource::Manual],
    'planned' => [TransactionSource::Planned],
]);

test('a hand-entered row the feed adopted matches like a feed row', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'WOOLWORTHS 1234 SYDNEY', '-42.50']]);
    $adopted = stmtFeedRow($reconciliation, '2026-01-05', 'WOOLWORTHS 1234 SYDNEY', -4250);
    $adopted->update(['source' => TransactionSource::Manual, 'redbark_id' => 'rb_txn_adopted']);

    expect(stmtKinds(stmtBuild($reconciliation)))->toBe(['matched'])
        ->and(stmtLine($reconciliation, StatementLineKind::Matched)->transaction_id)->toBe($adopted->id);
});

test('a csv row imported earlier still matches', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'WOOLWORTHS 1234 SYDNEY', '-42.50']]);
    stmtFeedRow($reconciliation, '2026-01-05', 'WOOLWORTHS 1234 SYDNEY', -4250)->update(['source' => TransactionSource::Csv]);

    expect(stmtKinds(stmtBuild($reconciliation)))->toBe(['matched']);
});

test('an exact amount on the same day with an equal fingerprint matches', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'WOOLWORTHS 1234 SYDNEY', '-42.50']]);
    $feed = stmtFeedRow($reconciliation, '2026-01-05', 'WOOLWORTHS 1234 SYDNEY', -4250);

    stmtBuild($reconciliation);

    expect(stmtLine($reconciliation, StatementLineKind::Matched)->transaction_id)->toBe($feed->id)
        ->and(stmtKinds($reconciliation))->toBe(['matched']);
});

test('masked digits in the feed description still match the statement', function () {
    $reconciliation = stmtReconciliation([['08/01/2026', 'Direct Debit NIB - 64699390', '-90.59']]);
    stmtFeedRow($reconciliation, '2026-01-08', 'Direct Debit NIB - xxxx9390', -9059);

    expect(stmtKinds(stmtBuild($reconciliation)))->toBe(['matched']);
});

test('an amount one cent off does not match', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'WOOLWORTHS 1234 SYDNEY', '-42.50']]);
    stmtFeedRow($reconciliation, '2026-01-05', 'WOOLWORTHS 1234 SYDNEY', -4251);

    expect(stmtKinds(stmtBuild($reconciliation)))->toBe(['statement_only', 'feed_only']);
});

test('two identical lines claim two identical feed rows without a double claim', function () {
    $row = ['05/01/2026', 'OSKO TO SAVINGS', '-50.00'];
    $reconciliation = stmtReconciliation([$row, $row]);
    $first = stmtFeedRow($reconciliation, '2026-01-05', 'OSKO TO SAVINGS', -5000);
    $second = stmtFeedRow($reconciliation, '2026-01-05', 'OSKO TO SAVINGS', -5000);

    stmtBuild($reconciliation);

    expect($reconciliation->lines()->orderBy('id')->pluck('transaction_id')->all())->toBe([$first->id, $second->id]);
});

test('two identical lines against one feed row leave one statement-only line', function () {
    $row = ['05/01/2026', 'OSKO TO SAVINGS', '-50.00'];
    $reconciliation = stmtReconciliation([$row, $row]);
    stmtFeedRow($reconciliation, '2026-01-05', 'OSKO TO SAVINGS', -5000);

    expect(stmtKinds(stmtBuild($reconciliation)))->toBe(['matched', 'statement_only']);
});

test('a feed row three days away matches but four days does not', function (string $feedDate, array $kinds) {
    $reconciliation = stmtReconciliation([['10/01/2026', 'COLES 0456', '-12.00']]);
    stmtFeedRow($reconciliation, $feedDate, 'COLES 0456', -1200);

    expect(stmtKinds(stmtBuild($reconciliation)))->toBe($kinds);
})->with([
    'three days' => ['2026-01-13', ['matched']],
    'four days' => ['2026-01-14', ['statement_only', 'feed_only']],
]);

test('the nearest candidate wins over a farther one', function () {
    $reconciliation = stmtReconciliation([['10/01/2026', 'COLES 0456', '-12.00']]);
    stmtFeedRow($reconciliation, '2026-01-13', 'COLES 0456', -1200);
    $near = stmtFeedRow($reconciliation, '2026-01-11', 'COLES 0456', -1200);

    stmtBuild($reconciliation);

    expect(stmtLine($reconciliation, StatementLineKind::Matched)->transaction_id)->toBe($near->id);
});

test('a posted candidate wins over a pending one at equal distance', function () {
    $reconciliation = stmtReconciliation([['10/01/2026', 'COLES 0456', '-12.00']]);
    stmtFeedRow($reconciliation, '2026-01-10', 'COLES 0456', -1200, TransactionStatus::Pending);
    $posted = stmtFeedRow($reconciliation, '2026-01-10', 'COLES 0456', -1200);

    stmtBuild($reconciliation);

    expect(stmtLine($reconciliation, StatementLineKind::Matched)->transaction_id)->toBe($posted->id);
});

test('feed rows the statement does not show become feed-only, pending included', function () {
    $reconciliation = stmtReconciliation([]);
    $posted = stmtFeedRow($reconciliation, '2026-01-15', 'KMART 1111', -900);
    $pending = stmtFeedRow($reconciliation, '2026-01-31', 'KMART 2222', -500, TransactionStatus::Pending);
    stmtFeedRow($reconciliation, '2026-02-01', 'KMART 3333', -700);
    stmtFeedRow($reconciliation, '2025-12-31', 'KMART 4444', -700);

    stmtBuild($reconciliation);

    expect($reconciliation->lines()->where('kind', StatementLineKind::FeedOnly)->orderBy('id')->pluck('transaction_id')->all())
        ->toBe([$posted->id, $pending->id]);
});

test('a statement line outside the period is counted and creates no line', function () {
    $reconciliation = stmtReconciliation([
        ['31/12/2025', 'KMART 1111', '-9.00'],
        ['01/02/2026', 'KMART 1111', '-9.00'],
    ]);

    stmtBuild($reconciliation);

    expect($reconciliation->lines_outside_period)->toBe(2)
        ->and($reconciliation->lines()->count())->toBe(0);
});

test('a re-upload carries user work forward and drops vanished lines', function () {
    $matchedRow = ['05/01/2026', 'WOOLWORTHS 1234', '-42.50'];
    $ignoredRow = ['06/01/2026', 'BANK FEE', '-5.00'];
    $vanishingRow = ['07/01/2026', 'COLES 0456', '-12.00'];

    $reconciliation = stmtReconciliation([$matchedRow, $ignoredRow, $vanishingRow]);
    stmtFeedRow($reconciliation, '2026-01-05', 'WOOLWORTHS 1234', -4250);
    $feedOnly = stmtFeedRow($reconciliation, '2026-01-20', 'UBER TRIP', -2350);
    stmtBuild($reconciliation);

    stmtLine($reconciliation, StatementLineKind::Matched)->update(['checked_at' => now()]);
    $reconciler = app(StatementReconciler::class);
    $reconciler->resolveIgnore(
        $reconciliation->lines()->where('description', 'BANK FEE')->sole(),
        'Fee reversed next month',
    );
    stmtLine($reconciliation, StatementLineKind::FeedOnly)->update(['checked_at' => now()]);

    stmtUpload($reconciliation, [$matchedRow, $ignoredRow]);
    stmtBuild($reconciliation);

    $ignored = $reconciliation->lines()->where('description', 'BANK FEE')->sole();

    expect(stmtLine($reconciliation, StatementLineKind::Matched)->isChecked())->toBeTrue()
        ->and($ignored->resolution)->toBe(StatementLineResolution::Ignored)
        ->and($ignored->note)->toBe('Fee reversed next month')
        ->and($ignored->isChecked())->toBeTrue()
        ->and(stmtLine($reconciliation, StatementLineKind::FeedOnly)->transaction_id)->toBe($feedOnly->id)
        ->and(stmtLine($reconciliation, StatementLineKind::FeedOnly)->isChecked())->toBeTrue()
        ->and($reconciliation->lines()->where('description', 'COLES 0456')->exists())->toBeFalse();
});

test('resolveImport creates a csv transaction through the ingestor', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'RENT PAYMENT', '-500.00']]);
    $plan = PlannedTransaction::factory()->for($reconciliation->user)->for($reconciliation->account)->create([
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'start_date' => '2026-01-05',
        'is_active' => true,
    ]);
    stmtBuild($reconciliation);
    $line = stmtLine($reconciliation, StatementLineKind::StatementOnly);

    $transaction = app(StatementReconciler::class)->resolveImport($line);

    $line->refresh();

    expect($transaction->source)->toBe(TransactionSource::Csv)
        ->and($transaction->csv_hash)->toBe($line->csv_hash)
        ->and($transaction->amount)->toBe(-50000)
        ->and($transaction->direction)->toBe(TransactionDirection::Debit)
        ->and($transaction->status)->toBe(TransactionStatus::Posted)
        ->and($transaction->planned_transaction_id)->toBe($plan->id)
        ->and($line->kind)->toBe(StatementLineKind::Matched)
        ->and($line->transaction_id)->toBe($transaction->id)
        ->and($line->resolution)->toBe(StatementLineResolution::Imported)
        ->and($line->isChecked())->toBeTrue();

    expect(fn () => app(StatementReconciler::class)->resolveImport($line))
        ->toThrow(StatementLineNotResolvable::class);
});

test('resolveImport refuses a row folded into its parent', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'INTL FEE', '-1.20']]);
    stmtBuild($reconciliation);
    $line = stmtLine($reconciliation, StatementLineKind::StatementOnly);

    $parent = stmtFeedRow($reconciliation, '2026-01-05', 'PURCHASE', -4000);
    $fee = stmtFeedRow($reconciliation, '2026-01-05', 'INTL FEE', -120);
    $fee->update(['csv_hash' => $line->csv_hash, 'folded_into_transaction_id' => $parent->id]);
    $fee->delete();

    expect(fn () => app(StatementReconciler::class)->resolveImport($line))
        ->toThrow(StatementLineAlreadyFolded::class);
});

test('resolveImport restores a row the user deleted', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'KMART 1111', '-9.00']]);
    stmtBuild($reconciliation);
    $line = stmtLine($reconciliation, StatementLineKind::StatementOnly);

    $deleted = stmtFeedRow($reconciliation, '2026-01-05', 'KMART 1111', -900);
    $deleted->update(['csv_hash' => $line->csv_hash]);
    $deleted->delete();

    $transaction = app(StatementReconciler::class)->resolveImport($line);

    expect($transaction->id)->toBe($deleted->id)
        ->and($transaction->fresh()->trashed())->toBeFalse()
        ->and(Transaction::query()->where('account_id', $reconciliation->account_id)->count())->toBe(1);
});

test('resolveImport reuses a live row with the same hash instead of inserting a duplicate', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'KMART 1111', '-9.00']]);
    stmtBuild($reconciliation);
    $line = stmtLine($reconciliation, StatementLineKind::StatementOnly);

    $live = stmtFeedRow($reconciliation, '2026-01-05', '', -900);
    $live->update(['csv_hash' => $line->csv_hash]);

    $transaction = app(StatementReconciler::class)->resolveImport($line);

    expect($transaction->id)->toBe($live->id)
        ->and($transaction->fresh()->description)->toBe('KMART 1111')
        ->and($line->fresh()->transaction_id)->toBe($live->id)
        ->and(Transaction::query()->where('account_id', $reconciliation->account_id)->count())->toBe(1);
});

test('resolveLink pairs the lines and deletes the feed-only one', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'PAYPAL *STEAM', '-20.00']]);
    $feed = stmtFeedRow($reconciliation, '2026-01-06', 'STEAM GAMES', -2000);
    stmtBuild($reconciliation);
    $line = stmtLine($reconciliation, StatementLineKind::StatementOnly);
    $feedOnly = stmtLine($reconciliation, StatementLineKind::FeedOnly);

    app(StatementReconciler::class)->resolveLink($line, $feedOnly);

    $line->refresh();

    expect($line->kind)->toBe(StatementLineKind::Matched)
        ->and($line->transaction_id)->toBe($feed->id)
        ->and($line->resolution)->toBe(StatementLineResolution::Linked)
        ->and($line->isChecked())->toBeTrue()
        ->and(StatementReconciliationLine::query()->find($feedOnly->id))->toBeNull();
});

test('resolveLink refuses a pair from different reconciliations', function () {
    $line = StatementReconciliationLine::factory()->statementOnly()->create();
    $feedOnly = StatementReconciliationLine::factory()->feedOnly()->create();

    expect(fn () => app(StatementReconciler::class)->resolveLink($line, $feedOnly))
        ->toThrow(StatementLineNotResolvable::class);
});

test('resolveLink refuses a feed-only line whose transaction was deleted', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'PAYPAL *STEAM', '-20.00']]);
    $feed = stmtFeedRow($reconciliation, '2026-01-06', 'STEAM GAMES', -2000);
    stmtBuild($reconciliation);
    $line = stmtLine($reconciliation, StatementLineKind::StatementOnly);
    $feedOnly = stmtLine($reconciliation, StatementLineKind::FeedOnly);

    $feed->forceDelete();
    $feedOnly->refresh();

    expect(fn () => app(StatementReconciler::class)->resolveLink($line, $feedOnly))
        ->toThrow(StatementLineNotResolvable::class);

    expect($line->fresh()->kind)->toBe(StatementLineKind::StatementOnly)
        ->and(StatementReconciliationLine::query()->find($feedOnly->id))->not->toBeNull();
});

test('resolveIgnore refuses a matched line', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'KMART 1111', '-9.00']]);
    stmtFeedRow($reconciliation, '2026-01-05', 'KMART 1111', -900);
    stmtBuild($reconciliation);
    $matched = stmtLine($reconciliation, StatementLineKind::Matched);

    expect(fn () => app(StatementReconciler::class)->resolveIgnore($matched, 'noise'))
        ->toThrow(StatementLineNotResolvable::class);

    expect($matched->fresh()->resolution)->toBeNull();
});

test('resolveIgnore refuses a feed-only line', function () {
    $reconciliation = stmtReconciliation([]);
    stmtFeedRow($reconciliation, '2026-01-05', 'KMART 1111', -900);
    stmtBuild($reconciliation);
    $feedOnly = stmtLine($reconciliation, StatementLineKind::FeedOnly);

    expect(fn () => app(StatementReconciler::class)->resolveIgnore($feedOnly, 'noise'))
        ->toThrow(StatementLineNotResolvable::class);

    expect($feedOnly->fresh()->resolution)->toBeNull()
        ->and($feedOnly->fresh()->isChecked())->toBeFalse();
});

test('resolveLink refuses a feed row the user has since deleted', function () {
    $reconciliation = stmtReconciliation([['05/01/2026', 'PAYPAL *STEAM', '-20.00']]);
    $feed = stmtFeedRow($reconciliation, '2026-01-06', 'STEAM GAMES', -2000);
    stmtBuild($reconciliation);
    $line = stmtLine($reconciliation, StatementLineKind::StatementOnly);
    $feedOnly = stmtLine($reconciliation, StatementLineKind::FeedOnly);

    $feed->delete();

    expect(fn () => app(StatementReconciler::class)->resolveLink($line, $feedOnly->fresh()))
        ->toThrow(StatementLineNotResolvable::class);

    expect($line->fresh()->kind)->toBe(StatementLineKind::StatementOnly)
        ->and($feedOnly->fresh())->not->toBeNull();
});

test('close refuses while a line is unchecked and succeeds once all are checked', function () {
    $reconciliation = StatementReconciliation::factory()->create();
    $line = StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->statementOnly()->create();
    StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->matched()->checked()->create();
    $reconciler = app(StatementReconciler::class);

    expect(fn () => $reconciler->close($reconciliation))->toThrow(StatementReconciliationIncomplete::class);

    $reconciler->resolveIgnore($line, 'Not ours');
    $reconciler->close($reconciliation);

    expect($reconciliation->refresh()->status)->toBe(StatementReconciliationStatus::Closed)
        ->and($reconciliation->closed_at)->not->toBeNull();
});

test('every mutation on a closed reconciliation throws until it is reopened', function () {
    $reconciliation = StatementReconciliation::factory()->closed()->create();
    $line = StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->statementOnly()->create();
    $feedOnly = StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->feedOnly()->create();
    $reconciler = app(StatementReconciler::class);

    foreach ([
        fn () => $reconciler->build($reconciliation),
        fn () => $reconciler->resolveImport($line),
        fn () => $reconciler->resolveLink($line, $feedOnly),
        fn () => $reconciler->resolveIgnore($line, 'x'),
        fn () => $reconciler->close($reconciliation),
    ] as $mutation) {
        expect($mutation)->toThrow(StatementReconciliationClosed::class);
    }

    $reconciler->reopen($reconciliation);

    $reconciler->resolveIgnore($line->fresh(), 'Allowed again');

    expect($reconciliation->refresh()->status)->toBe(StatementReconciliationStatus::Open)
        ->and($reconciliation->closed_at)->toBeNull()
        ->and($line->fresh()->resolution)->toBe(StatementLineResolution::Ignored);
});

test('canClose is false while any line is unchecked', function () {
    $reconciliation = StatementReconciliation::factory()->create();
    StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->matched()->checked()->create();
    $unchecked = StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->feedOnly()->create();

    expect($reconciliation->canClose())->toBeFalse();

    $unchecked->update(['checked_at' => now()]);

    expect($reconciliation->canClose())->toBeTrue();
});

test('canClose is false while a ticked statement-only line is unresolved', function () {
    $reconciliation = StatementReconciliation::factory()->create();
    $line = StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->statementOnly()->checked()->create();

    expect($reconciliation->canClose())->toBeFalse();

    $line->update(['resolution' => StatementLineResolution::Ignored, 'note' => 'Bank error, reversed']);

    expect($reconciliation->canClose())->toBeTrue();
});

test('a statement with unreadable rows is refused and the existing build is kept', function (string $csv) {
    $reconciliation = stmtReconciliation([['05/01/2026', 'KMART 1111', '-9.00']]);
    stmtBuild($reconciliation);
    $before = $reconciliation->lines()->pluck('id')->all();

    Storage::disk('local')->put($reconciliation->stored_path, $csv);

    expect(fn () => app(StatementReconciler::class)->build($reconciliation))
        ->toThrow(StatementFileUnreadable::class);

    expect($reconciliation->lines()->pluck('id')->all())->toBe($before);
})->with([
    'a row missing its amount' => ["Date,Description,Amount\n05/01/2026,KMART 1111,-9.00\n06/01/2026,KMART 2222,\n"],
    'mapped columns absent from the file' => ["Posted,Narrative,Value\n05/01/2026,KMART 1111,-9.00\n"],
    'a malformed date' => ["Date,Description,Amount\nnot-a-date,KMART 1111,-9.00\n"],
]);

test('one reconciliation per account and period start', function () {
    $existing = StatementReconciliation::factory()->create();

    expect(fn () => StatementReconciliation::factory()->create([
        'user_id' => $existing->user_id,
        'account_id' => $existing->account_id,
        'period_start' => $existing->period_start,
    ]))->toThrow(UniqueConstraintViolationException::class);
});
