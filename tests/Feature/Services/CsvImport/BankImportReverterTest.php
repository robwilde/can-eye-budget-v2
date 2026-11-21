<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BankImportStatus;
use App\Exceptions\BankImportRevertRefused;
use App\Jobs\ImportCsvTransactionsJob;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\BankImportReverter;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->csvImport()->create();
});

function csvImportFor(User $user, Account $account, array $rows): BankImport
{
    $lines = ['Date,Description,Amount'];

    foreach ($rows as [$date, $description, $amount]) {
        $lines[] = "{$date},{$description},{$amount}";
    }

    $path = 'bank-imports/'.bin2hex(random_bytes(6)).'.csv';
    Storage::disk('local')->put($path, implode("\n", $lines)."\n");

    return BankImport::factory()->for($user)->for($account)->create([
        'stored_path' => $path,
        'column_mapping' => [CsvColumnMapper::FIELD_DATE => 'Date', CsvColumnMapper::FIELD_DESCRIPTION => 'Description', CsvColumnMapper::FIELD_AMOUNT => 'Amount'],
    ]);
}

function runCsvImport(BankImport $import): BankImport
{
    new ImportCsvTransactionsJob($import)->handle(new CsvParserService, app(TransactionIngestor::class));

    return $import->fresh();
}

function tenRows(): array
{
    return array_map(fn (int $i): array => ['2026-03-'.mb_str_pad((string) $i, 2, '0', STR_PAD_LEFT), "Shop {$i}", '-'.$i.'.50'], range(1, 10));
}

it('reverts the transactions an import created', function () {
    $import = runCsvImport(csvImportFor($this->user, $this->account, tenRows()));

    expect(Transaction::query()->where('bank_import_id', $import->id)->count())->toBe(10);

    $reverted = app(BankImportReverter::class)->revert($import);

    expect($reverted->status)->toBe(BankImportStatus::Previewing)
        ->and($reverted->stored_path)->toBe($import->stored_path)
        ->and(Storage::disk('local')->exists($import->stored_path))->toBeTrue()
        ->and(Transaction::query()->where('bank_import_id', $import->id)->count())->toBe(0)
        ->and(Transaction::withTrashed()->where('bank_import_id', $import->id)->whereNotNull('deleted_at')->count())->toBe(10)
        ->and($reverted->imported_count)->toBe(0);
});

it('leaves transactions the import only matched untouched', function () {
    $first = runCsvImport(csvImportFor($this->user, $this->account, tenRows()));
    $second = runCsvImport(csvImportFor($this->user, $this->account, [...array_slice(tenRows(), 0, 3), ['2026-04-01', 'New shop', '-9.99']]));

    expect($second->imported_count)->toBe(1);

    app(BankImportReverter::class)->revert($second);

    expect(Transaction::query()->where('bank_import_id', $first->id)->count())->toBe(10)
        ->and(Transaction::query()->where('bank_import_id', $second->id)->count())->toBe(0)
        ->and(Transaction::query()->where('account_id', $this->account->id)->count())->toBe(10);
});

it('soft-deletes rows the import restored on revert', function () {
    $first = runCsvImport(csvImportFor($this->user, $this->account, tenRows()));
    $row = Transaction::query()->where('bank_import_id', $first->id)->orderBy('id')->first();
    $row->delete();

    $second = runCsvImport(csvImportFor($this->user, $this->account, array_slice(tenRows(), 0, 3)));

    expect($row->fresh()->restored_by_bank_import_id)->toBe($second->id)
        ->and($row->fresh()->bank_import_id)->toBe($first->id)
        ->and($row->fresh()->deleted_at)->toBeNull();

    app(BankImportReverter::class)->revert($second);

    expect($row->fresh()->deleted_at)->not->toBeNull()
        ->and(Transaction::query()->where('bank_import_id', $first->id)->count())->toBe(9);
});

it('re-publishes a reverted import without creating duplicates', function () {
    $import = runCsvImport(csvImportFor($this->user, $this->account, tenRows()));
    app(BankImportReverter::class)->revert($import);

    $again = runCsvImport($import);

    $rows = Transaction::query()->where('account_id', $this->account->id)->get();

    expect($again->status)->toBe(BankImportStatus::Completed)
        ->and($again->restored_count)->toBe(10)
        ->and($rows)->toHaveCount(10)
        ->and($rows->every(fn (Transaction $t): bool => $t->restored_by_bank_import_id === $import->id))->toBeTrue()
        ->and(Transaction::withTrashed()->where('account_id', $this->account->id)->count())->toBe(10);

    app(BankImportReverter::class)->revert($again);

    expect(Transaction::query()->where('account_id', $this->account->id)->count())->toBe(0);
});

it('refuses to revert an import that is not completed', function () {
    $import = csvImportFor($this->user, $this->account, tenRows());

    expect(fn () => app(BankImportReverter::class)->revert($import))->toThrow(BankImportRevertRefused::class);
});

it('never touches another user\'s rows', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->csvImport()->create();
    $theirs = Transaction::factory()->for($other)->for($otherAccount)->create(['bank_import_id' => runCsvImport(csvImportFor($this->user, $this->account, tenRows()))->id]);
    $import = BankImport::query()->where('user_id', $this->user->id)->first();

    app(BankImportReverter::class)->revert($import);

    expect($theirs->fresh()->deleted_at)->toBeNull();
});

it('keeps a row a bank feed has adopted', function () {
    $import = runCsvImport(csvImportFor($this->user, $this->account, tenRows()));
    $row = Transaction::query()->where('bank_import_id', $import->id)->first();
    $row->update(['redbark_id' => 'rb-1']);

    app(BankImportReverter::class)->revert($import);

    expect($row->fresh()->deleted_at)->toBeNull()
        ->and(Transaction::query()->where('bank_import_id', $import->id)->count())->toBe(1);
});

it('keeps the import as it was when the revert fails and lets it be retried', function () {
    $import = runCsvImport(csvImportFor($this->user, $this->account, tenRows()));
    Event::listen('eloquent.deleting: '.Transaction::class, fn () => throw new RuntimeException('boom'));

    expect(fn () => app(BankImportReverter::class)->revert($import))->toThrow(RuntimeException::class);

    expect($import->fresh()->status)->toBe(BankImportStatus::Completed)
        ->and(Transaction::query()->where('bank_import_id', $import->id)->count())->toBe(10);

    Event::forget('eloquent.deleting: '.Transaction::class);

    app(BankImportReverter::class)->revert($import);

    expect($import->fresh()->status)->toBe(BankImportStatus::Previewing)
        ->and(Transaction::query()->where('bank_import_id', $import->id)->count())->toBe(0);
});

it('reverts a row the user edited after the import', function () {
    $import = runCsvImport(csvImportFor($this->user, $this->account, tenRows()));
    $row = Transaction::query()->where('bank_import_id', $import->id)->orderBy('id')->first();
    $row->createChild(['notes' => 'edited after import']);

    app(BankImportReverter::class)->revert($import);

    expect(Transaction::query()->where('account_id', $this->account->id)->count())->toBe(0)
        ->and($import->fresh()->status)->toBe(BankImportStatus::Previewing);

    $again = runCsvImport($import->fresh());

    expect($again->restored_count)->toBe(10)
        ->and(Transaction::query()->where('account_id', $this->account->id)->count())->toBe(10);
});

it('takes back a row it created when the same import is confirmed again after another import restored it', function () {
    $first = runCsvImport(csvImportFor($this->user, $this->account, tenRows()));
    app(BankImportReverter::class)->revert($first);
    $second = runCsvImport(csvImportFor($this->user, $this->account, array_slice(tenRows(), 0, 3)));
    $row = Transaction::query()->where('restored_by_bank_import_id', $second->id)->first();

    runCsvImport($first->fresh());

    expect($row->fresh()->restored_by_bank_import_id)->toBeNull();
});
