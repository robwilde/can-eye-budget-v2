<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BankImportStatus;
use App\Enums\TransactionSource;
use App\Jobs\ImportCsvTransactionsJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Random\RandomException;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * @throws RandomException
 */
function makeBankImportFromFixture(string $fixture = 'StatementCsv-Westpac.csv'): BankImport
{
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    $contents = file_get_contents(base_path('tests/Fixtures/'.$fixture));
    $storedPath = 'bank-imports/'.bin2hex(random_bytes(8)).'.csv';
    Storage::disk('local')->put($storedPath, $contents);

    return BankImport::factory()
        ->for($user)
        ->for($account)
        ->create([
            'original_filename' => $fixture,
            'stored_path' => $storedPath,
            'column_mapping' => [
                CsvColumnMapper::FIELD_DATE => 'Entered Date',
                CsvColumnMapper::FIELD_DESCRIPTION => 'Transaction Description',
                CsvColumnMapper::FIELD_AMOUNT => 'Amount',
                CsvColumnMapper::FIELD_BALANCE => 'Balance',
            ],
        ]);
}

test('imports rows into transactions with source=csv', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);

    $bankImport = makeBankImportFromFixture();

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService());

    $bankImport->refresh();
    expect($bankImport->status)->toBe(BankImportStatus::Completed)
        ->and($bankImport->imported_count)->toBeGreaterThan(150)
        ->and(Transaction::count())->toBe($bankImport->imported_count);

    $sample = Transaction::query()
        ->where('account_id', $bankImport->account_id)
        ->first();

    expect($sample->source)->toBe(TransactionSource::Csv)
        ->and($sample->csv_hash)->not->toBeNull()
        ->and($sample->user_id)->toBe($bankImport->user_id);
});

test('re-running the same import is idempotent (dedupe via csv_hash)', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);

    $bankImport = makeBankImportFromFixture();

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService());

    $bankImport->refresh();
    $firstImported = $bankImport->imported_count;
    $totalAfterFirst = Transaction::count();

    expect($firstImported)->toBeGreaterThan(0);

    $bankImport->update([
        'status' => BankImportStatus::Pending,
        'imported_count' => 0,
        'skipped_count' => 0,
        'restored_count' => 0,
        'row_count' => 0,
        'completed_at' => null,
    ]);

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService());

    $bankImport->refresh();
    expect($bankImport->status)->toBe(BankImportStatus::Completed)
        ->and($bankImport->imported_count)->toBe(0)
        ->and($bankImport->restored_count)->toBe(0)
        ->and($bankImport->skipped_count)->toBe(0)
        ->and(Transaction::count())->toBe($totalAfterFirst);
});

test('status transitions through importing then completed', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);

    $bankImport = makeBankImportFromFixture();

    expect($bankImport->status)->toBe(BankImportStatus::Pending)
        ->and($bankImport->started_at)->toBeNull();

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService());

    $bankImport->refresh();
    expect($bankImport->status)->toBe(BankImportStatus::Completed)
        ->and($bankImport->started_at)->not->toBeNull()
        ->and($bankImport->completed_at)->not->toBeNull();
});

test('dispatches RunTransactionAnalysisJob after successful import', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);

    $bankImport = makeBankImportFromFixture();

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService());

    Queue::assertPushed(
        RunTransactionAnalysisJob::class,
        fn (RunTransactionAnalysisJob $job) => $job->user->id === $bankImport->user_id,
    );
});

test('failure transitions status to Failed and records error_summary', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);

    $bankImport = makeBankImportFromFixture();
    $bankImport->update(['stored_path' => 'bank-imports/does-not-exist.csv']);

    expect(fn () => new ImportCsvTransactionsJob($bankImport)
        ->handle(new CsvParserService()))->toThrow(Exception::class);

    $bankImport->refresh();
    expect($bankImport->status)->toBe(BankImportStatus::Failed)
        ->and($bankImport->error_summary)->not->toBeNull()
        ->and($bankImport->completed_at)->not->toBeNull();

    Queue::assertNotPushed(RunTransactionAnalysisJob::class);
});

test('re-importing restores a soft-deleted transaction and preserves its category', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);

    $bankImport = makeBankImportFromFixture();

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService());

    $bankImport->refresh();
    expect($bankImport->status)->toBe(BankImportStatus::Completed)
        ->and($bankImport->imported_count)->toBeGreaterThan(0);

    $target = Transaction::query()
        ->where('account_id', $bankImport->account_id)
        ->whereNotNull('csv_hash')
        ->first();

    $category = Category::factory()->create();
    $target->update(['category_id' => $category->id]);
    $targetId = $target->id;
    $targetHash = $target->csv_hash;
    $target->delete();

    expect(Transaction::query()->find($targetId))->toBeNull()
        ->and(Transaction::withTrashed()->find($targetId)->trashed())->toBeTrue();

    $bankImport->update([
        'status' => BankImportStatus::Pending,
        'imported_count' => 0,
        'skipped_count' => 0,
        'restored_count' => 0,
        'row_count' => 0,
        'row_errors' => null,
        'completed_at' => null,
    ]);

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService());

    $bankImport->refresh();
    $restored = Transaction::query()->find($targetId);

    expect($bankImport->status)->toBe(BankImportStatus::Completed)
        ->and($bankImport->restored_count)->toBe(1)
        ->and($bankImport->imported_count)->toBe(0)
        ->and($restored)->not->toBeNull()
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->csv_hash)->toBe($targetHash)
        ->and($restored->category_id)->toBe($category->id);
});

test('per-row failure does not abort the import and is recorded in row_errors', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);

    $eventKey = 'eloquent.creating: '.Transaction::class;

    Transaction::creating(static function (Transaction $tx): void {
        if (str_contains($tx->description ?? '', 'INTENTIONAL_FAILURE')) {
            throw new RuntimeException('Simulated row failure for test');
        }
    });

    try {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->csvImport()->create();

        $csv = <<<'CSV'
        Date,Description,Amount
        01/01/2026,Good row one,100.00
        02/01/2026,INTENTIONAL_FAILURE row,200.00
        03/01/2026,Good row two,-300.00
        CSV;

        $storedPath = 'bank-imports/'.bin2hex(random_bytes(8)).'.csv';
        Storage::disk('local')->put($storedPath, $csv);

        $bankImport = BankImport::factory()
            ->for($user)
            ->for($account)
            ->create([
                'original_filename' => 'inline.csv',
                'stored_path' => $storedPath,
                'column_mapping' => [
                    CsvColumnMapper::FIELD_DATE => 'Date',
                    CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
                    CsvColumnMapper::FIELD_AMOUNT => 'Amount',
                ],
            ]);

        new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService());

        $bankImport->refresh();

        expect($bankImport->status)->toBe(BankImportStatus::Completed)
            ->and($bankImport->imported_count)->toBe(2)
            ->and($bankImport->skipped_count)->toBe(1)
            ->and($bankImport->row_errors)->toBeArray()
            ->and($bankImport->row_errors)->toHaveCount(1)
            ->and($bankImport->row_errors[0]['description'])->toContain('INTENTIONAL_FAILURE')
            ->and($bankImport->row_errors[0]['message'])->toBe('Could not import this row.');

        expect(Transaction::query()->where('account_id', $account->id)->count())->toBe(2);
    } finally {
        Event::forget($eventKey);
    }
});

test('uniqueId is the bankImport id', function () {
    $bankImport = makeBankImportFromFixture();
    $job = new ImportCsvTransactionsJob($bankImport);

    expect($job->uniqueId())->toBe($bankImport->id)
        ->and($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldBeUnique::class);
});

test('has WithoutOverlapping middleware keyed on bank import', function () {
    $bankImport = makeBankImportFromFixture();
    $job = new ImportCsvTransactionsJob($bankImport);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(Illuminate\Queue\Middleware\WithoutOverlapping::class);
});
