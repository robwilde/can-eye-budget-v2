<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PdfImportStatus;
use App\Enums\StatementLineKind;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Exceptions\Statement\PdfImportRefused;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\PdfImport;
use App\Models\PdfImportLine;
use App\Models\Statement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Statement\PdfImportPublisher;
use App\Services\Statement\StatementLineMatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->statement = Statement::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31']);
    $this->import = PdfImport::factory()->ready()->create(['user_id' => $this->user->id, 'statement_id' => $this->statement->id]);
    $this->publisher = app(PdfImportPublisher::class);
});

function importLine(PdfImport $import, string $date, int $cents, string $description = 'Line'): PdfImportLine
{
    return PdfImportLine::factory()->for($import)->create([
        'line_date' => $date,
        'raw_date' => $date,
        'amount' => $cents,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'description' => $description,
    ]);
}

function existingTransaction(Account $account, string $date, int $cents, array $attributes = []): Transaction
{
    return Transaction::factory()->for($account->user)->for($account)->create([
        'post_date' => $date,
        'amount' => $cents,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
    ] + $attributes);
}

function parsedLine(string $date, int $cents, string $hash = 'h'): App\Services\CsvImport\ParsedTransactionDto
{
    return new App\Services\CsvImport\ParsedTransactionDto(CarbonImmutable::parse($date), $cents, $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit, 'Anything', null, [], $hash);
}

function matchKinds(Account $account, array $lines, string $from = '2026-03-01', string $to = '2026-03-31'): array
{
    return collect(app(StatementLineMatcher::class)->match($account, new Collection($lines), CarbonPeriod::create($from, $to)))
        ->map(fn ($entry): string => $entry->kind->value)->all();
}

it('publishes unmatched lines and reconciles matched transactions', function () {
    $first = existingTransaction($this->account, '2026-03-05', -1000);
    $second = existingTransaction($this->account, '2026-03-10', -2000);
    importLine($this->import, '2026-03-05', -1000);
    importLine($this->import, '2026-03-11', -2000);
    importLine($this->import, '2026-03-12', -3000, 'New one');
    importLine($this->import, '2026-03-13', 4000, 'Salary');
    importLine($this->import, '2026-03-14', -500, 'Coffee');

    $published = $this->publisher->publish($this->import, $this->account);

    $created = Transaction::query()->where('pdf_import_id', $this->import->id)->orderBy('post_date')->get();

    expect($published->status)->toBe(PdfImportStatus::Published)
        ->and($published->account_id)->toBe($this->account->id)
        ->and($created)->toHaveCount(3)
        ->and($created->pluck('amount')->all())->toBe([-3000, 4000, -500])
        ->and($created->every(fn (Transaction $t): bool => $t->source === TransactionSource::Pdf && $t->account_id === $this->account->id && $t->user_id === $this->user->id))->toBeTrue()
        ->and($first->fresh()->reconciled_statement_id)->toBe($this->statement->id)
        ->and($second->fresh()->reconciled_statement_id)->toBe($this->statement->id)
        ->and($published->summary['found'])->toBe(5)
        ->and($published->summary['already_recorded'])->toBe(2)
        ->and($published->summary['imported'])->toBe(3)
        ->and($published->summary['waiting'])->toBe(0);
});

it('finishes published with nothing to import when every line is already recorded', function () {
    existingTransaction($this->account, '2026-03-05', -1000);
    importLine($this->import, '2026-03-05', -1000);

    $published = $this->publisher->publish($this->import, $this->account);

    expect($published->status)->toBe(PdfImportStatus::Published)
        ->and($published->summary['imported'])->toBe(0)
        ->and(Transaction::query()->where('pdf_import_id', $this->import->id)->count())->toBe(0);
});

it('holds back lines whose date could not be read', function () {
    PdfImportLine::factory()->for($this->import)->unreadableDate()->create(['amount' => -700]);
    importLine($this->import, '2026-03-05', -1000);

    $published = $this->publisher->publish($this->import, $this->account);

    expect($published->summary['waiting'])->toBe(1)
        ->and($published->summary['imported'])->toBe(1)
        ->and(Transaction::query()->where('pdf_import_id', $this->import->id)->count())->toBe(1);
});

it('matches again at publish when a sync landed after the review', function () {
    importLine($this->import, '2026-03-05', -1000);

    $before = $this->publisher->plan($this->import, $this->account);
    existingTransaction($this->account, '2026-03-05', -1000, ['source' => TransactionSource::Redbark, 'redbark_id' => 'rb-1']);
    $published = $this->publisher->publish($this->import, $this->account);

    expect($before->statementOnly)->toHaveCount(1)
        ->and($published->summary['already_recorded'])->toBe(1)
        ->and($published->summary['imported'])->toBe(0);
});

it('claims each existing transaction only once', function () {
    $transaction = existingTransaction($this->account, '2026-03-10', -10000);

    $entries = app(StatementLineMatcher::class)->match(
        $this->account,
        new Collection([parsedLine('2026-03-10', -10000, 'a'), parsedLine('2026-03-10', -10000, 'b')]),
        CarbonPeriod::create('2026-03-01', '2026-03-31'),
    );

    expect(collect($entries)->pluck('kind')->map->value->all())->toBe(['matched', 'statement_only'])
        ->and($entries[0]->transaction->is($transaction))->toBeTrue()
        ->and($entries[1]->transaction)->toBeNull();
});

it('matches on amount and date within three days regardless of name or source', function () {
    existingTransaction($this->account, '2026-03-12', -4250, ['description' => 'Totally different words', 'source' => TransactionSource::Manual]);
    existingTransaction($this->account, '2026-03-20', -900, ['description' => '', 'source' => TransactionSource::Csv]);
    existingTransaction($this->account, '2026-03-25', -300, ['source' => TransactionSource::Redbark, 'redbark_id' => 'rb-2']);
    existingTransaction($this->account, '2026-03-28', -700);

    expect(matchKinds($this->account, [
        parsedLine('2026-03-10', -4250, 'a'),
        parsedLine('2026-03-20', -900, 'b'),
        parsedLine('2026-03-26', -300, 'c'),
        parsedLine('2026-03-24', -700, 'd'),
        parsedLine('2026-03-12', -4251, 'e'),
    ]))->toBe(['matched', 'matched', 'matched', 'statement_only', 'statement_only', 'feed_only']);
});

it('matches across a period that is not a calendar month', function () {
    $transaction = existingTransaction($this->account, '2026-02-20', -1500);

    $entries = app(StatementLineMatcher::class)->match($this->account, new Collection([parsedLine('2026-02-18', -1500)]), CarbonPeriod::create('2026-02-10', '2026-02-24'));

    expect($entries[0]->transaction->is($transaction))->toBeTrue();
});

it('reports a transaction the statement does not show as feed only and ignores ones outside the period', function () {
    existingTransaction($this->account, '2026-03-15', -111);
    existingTransaction($this->account, '2026-02-01', -222);

    expect(matchKinds($this->account, [parsedLine('2026-03-10', -999)]))->toBe(['statement_only', 'feed_only']);
});

it('never matches another user\'s transactions', function () {
    $other = User::factory()->create();
    $theirs = existingTransaction(Account::factory()->for($other)->create(), '2026-03-10', -5000);
    $mineElsewhere = existingTransaction(Account::factory()->for($this->user)->create(), '2026-03-10', -5000);

    expect(matchKinds($this->account, [parsedLine('2026-03-10', -5000)]))->toBe(['statement_only']);

    importLine($this->import, '2026-03-10', -5000);
    $this->publisher->publish($this->import, $this->account);

    expect($theirs->fresh()->reconciled_statement_id)->toBeNull()
        ->and($mineElsewhere->fresh()->reconciled_statement_id)->toBeNull();
});

it('still matches a transaction another statement reconciled and keeps that statement\'s mark', function () {
    $other = Statement::factory()->for($this->user)->create();
    $transaction = existingTransaction($this->account, '2026-03-10', -5000, ['reconciled_statement_id' => $other->id]);
    importLine($this->import, '2026-03-10', -5000);

    $published = $this->publisher->publish($this->import, $this->account);

    expect($published->summary['already_recorded'])->toBe(1)
        ->and($published->summary['imported'])->toBe(0)
        ->and($transaction->fresh()->reconciled_statement_id)->toBe($other->id);

    $this->publisher->revert($this->import);

    expect($transaction->fresh()->reconciled_statement_id)->toBe($other->id);
});

it('does not use up a transaction on a line a few days off when a nearer pairing exists', function () {
    existingTransaction($this->account, '2026-03-07', -5000);
    existingTransaction($this->account, '2026-03-11', -5000);

    expect(matchKinds($this->account, [parsedLine('2026-03-10', -5000, 'a'), parsedLine('2026-03-13', -5000, 'b')]))->toBe(['matched', 'matched']);
});

it('prefers a transaction on the same day over an earlier one inside the window', function () {
    $near = existingTransaction($this->account, '2026-03-10', -5000);
    existingTransaction($this->account, '2026-03-08', -5000);

    $entries = app(StatementLineMatcher::class)->match($this->account, new Collection([parsedLine('2026-03-10', -5000)]), CarbonPeriod::create('2026-03-01', '2026-03-31'));

    expect($entries[0]->transaction->is($near))->toBeTrue();
});

it('does not match the children of a split transaction', function () {
    $parent = existingTransaction($this->account, '2026-03-10', -15000);
    existingTransaction($this->account, '2026-03-10', -5000, ['split_parent_id' => $parent->id]);

    expect(matchKinds($this->account, [parsedLine('2026-03-11', -5000)]))->toBe(['statement_only', 'feed_only']);
});

it('leaves a bank-feed row that adopted a pdf-imported row when the import is reverted', function () {
    importLine($this->import, '2026-03-12', -4000, 'COFFEE SHOP 1234');
    $this->publisher->publish($this->import, $this->account);
    $created = Transaction::query()->where('pdf_import_id', $this->import->id)->sole();
    $created->update(['redbark_id' => 'rb-9']);

    $this->publisher->revert($this->import);

    expect($created->fresh()->deleted_at)->toBeNull()
        ->and($created->fresh()->pdf_import_id)->toBeNull()
        ->and($created->fresh()->reconciled_statement_id)->toBeNull();
});

it('clears the reconciled mark of the current version of an edited matched transaction on revert', function () {
    $matched = existingTransaction($this->account, '2026-03-05', -1000);
    importLine($this->import, '2026-03-05', -1000);
    $this->publisher->publish($this->import, $this->account);
    $child = $matched->fresh()->createChild(['notes' => 'edited after publish']);

    $this->publisher->revert($this->import);

    expect($child->fresh()->reconciled_statement_id)->toBeNull()
        ->and($matched->fresh()->reconciled_statement_id)->toBeNull();
});

it('removes split children and releases transfers of the rows it created when reverted', function () {
    importLine($this->import, '2026-03-12', -4000);
    $this->publisher->publish($this->import, $this->account);
    $created = Transaction::query()->where('pdf_import_id', $this->import->id)->sole();
    $child = existingTransaction($this->account, '2026-03-12', -4000, ['split_parent_id' => $created->id]);

    $this->publisher->revert($this->import);

    expect(Transaction::query()->whereKey($child->id)->exists())->toBeFalse();
});

it('refuses to change the account of a published import', function () {
    importLine($this->import, '2026-03-05', -1000);
    $this->publisher->publish($this->import, $this->account);
    $other = Account::factory()->for($this->user)->create();

    expect(fn () => $this->publisher->changeAccount($this->import, $other))->toThrow(PdfImportRefused::class, 'revert it first');

    expect($this->import->fresh()->account_id)->toBe($this->account->id)
        ->and($this->import->fresh()->status)->toBe(PdfImportStatus::Published);
});

it('changes the account of a ready import and regenerates its lines', function () {
    $line = importLine($this->import, '2026-03-05', -1000);
    $line->update(['kind' => StatementLineKind::Matched]);
    $other = Account::factory()->for($this->user)->create();

    $this->publisher->changeAccount($this->import, $other);

    expect($this->import->fresh()->account_id)->toBe($other->id)
        ->and($line->fresh()->kind)->toBe(StatementLineKind::StatementOnly);
});

it('reverts exactly the rows the import created or reconciled', function () {
    $matched = [existingTransaction($this->account, '2026-03-05', -1000), existingTransaction($this->account, '2026-03-06', -2000)];
    $unrelated = existingTransaction($this->account, '2026-03-07', -3000, ['reconciled_statement_id' => Statement::factory()->for($this->user)->create()->id]);
    importLine($this->import, '2026-03-05', -1000);
    importLine($this->import, '2026-03-06', -2000);
    importLine($this->import, '2026-03-12', -4000);
    importLine($this->import, '2026-03-13', -5000);
    importLine($this->import, '2026-03-14', -6000);
    $this->publisher->publish($this->import, $this->account);

    $created = Transaction::query()->where('pdf_import_id', $this->import->id)->pluck('id')->all();

    $reverted = $this->publisher->revert($this->import);

    expect($reverted->status)->toBe(PdfImportStatus::Reverted)
        ->and($created)->toHaveCount(3)
        ->and(Transaction::withTrashed()->whereIn('id', $created)->whereNotNull('deleted_at')->count())->toBe(3)
        ->and(Transaction::query()->whereIn('id', $created)->count())->toBe(0)
        ->and($matched[0]->fresh()->reconciled_statement_id)->toBeNull()
        ->and($matched[1]->fresh()->reconciled_statement_id)->toBeNull()
        ->and($matched[0]->fresh()->deleted_at)->toBeNull()
        ->and($unrelated->fresh()->reconciled_statement_id)->not->toBeNull()
        ->and($this->import->lines()->where('kind', StatementLineKind::StatementOnly->value)->count())->toBe(5);
});

it('can publish again after a revert without duplicating rows', function () {
    importLine($this->import, '2026-03-12', -4000);
    $this->publisher->publish($this->import, $this->account);
    $this->publisher->revert($this->import);

    $this->publisher->publish($this->import->fresh(), $this->account);

    expect(Transaction::query()->where('pdf_import_id', $this->import->id)->count())->toBe(1)
        ->and(Transaction::withTrashed()->where('pdf_import_id', $this->import->id)->count())->toBe(2);
});

it('refuses to publish or revert in the wrong state and to use another user\'s account', function () {
    $foreign = Account::factory()->for(User::factory()->create())->create();
    $pending = PdfImport::factory()->create(['user_id' => $this->user->id, 'statement_id' => $this->statement->id]);

    expect(fn () => $this->publisher->publish($pending, $this->account))->toThrow(PdfImportRefused::class)
        ->and(fn () => $this->publisher->publish($this->import, $foreign))->toThrow(PdfImportRefused::class)
        ->and(fn () => $this->publisher->revert($this->import))->toThrow(PdfImportRefused::class)
        ->and(fn () => $this->publisher->changeAccount($this->import, $foreign))->toThrow(PdfImportRefused::class);
});

it('marks an import failed only once it has been idle for over an hour', function () {
    $stuck = PdfImport::factory()->create(['user_id' => $this->user->id, 'statement_id' => $this->statement->id, 'status' => PdfImportStatus::Processing]);

    expect(fn () => $this->publisher->markFailed($stuck))->toThrow(PdfImportRefused::class, 'still running');

    $this->travel(61)->minutes();
    $failed = $this->publisher->markFailed($stuck->fresh());

    expect($failed->status)->toBe(PdfImportStatus::Failed)
        ->and(fn () => $this->publisher->markFailed($this->import))->toThrow(PdfImportRefused::class);
});

it('keeps the account balance history in step when a publish and a revert change the ledger', function () {
    AccountDailyBalance::factory()->create(['account_id' => $this->account->id, 'balance_date' => '2026-03-01', 'start_balance' => 0, 'end_balance' => 0, 'currency' => 'AUD']);
    importLine($this->import, '2026-03-12', -4000);

    $this->publisher->publish($this->import, $this->account);
    $afterPublish = AccountDailyBalance::query()->where('account_id', $this->account->id)->whereDate('balance_date', '2026-03-12')->first()?->end_balance;
    $this->publisher->revert($this->import);
    $afterRevert = AccountDailyBalance::query()->where('account_id', $this->account->id)->whereDate('balance_date', '2026-03-12')->first()?->end_balance;

    expect($afterPublish)->not->toBeNull()
        ->and($afterRevert)->not->toBe($afterPublish);
});

it('lets a later bank-feed sync adopt a pdf-imported row instead of importing it twice', function () {
    importLine($this->import, '2026-03-12', -4000, 'COFFEE SHOP 1234');
    $this->publisher->publish($this->import, $this->account);
    $created = Transaction::query()->where('pdf_import_id', $this->import->id)->sole();

    $found = app(App\Services\RedbarkTransactionMatcher::class)->findExisting($this->account->id, -4000, CarbonImmutable::parse('2026-03-13'), 'coffee shop 5678');

    expect($found?->is($created))->toBeTrue();
});

it('refuses to publish to an untracked account', function () {
    $untracked = Account::factory()->for($this->user)->create(['is_tracked' => false]);
    importLine($this->import, '2026-03-12', -4000);

    expect(fn () => $this->publisher->publish($this->import, $untracked))->toThrow(PdfImportRefused::class)
        ->and(fn () => $this->publisher->changeAccount($this->import, $untracked))->toThrow(PdfImportRefused::class);
});
